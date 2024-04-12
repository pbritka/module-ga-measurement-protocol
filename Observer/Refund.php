<?php

namespace Pbritka\GaMeasurementProtocol\Observer;

use Br33f\Ga4\MeasurementProtocol\Service;
use Br33f\Ga4\MeasurementProtocol\Dto\Request\BaseRequest;
use Br33f\Ga4\MeasurementProtocol\Dto\Event\RefundEvent;
use Br33f\Ga4\MeasurementProtocol\Dto\Parameter\ItemParameter;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Pbritka\GaMeasurementProtocol\Helper\Ga as GaHelper;

class Refund implements ObserverInterface
{

    /**
     * @var bool
     */
    private $skipEvent = false;

    /**
     * @var ManagerInterface
     */
    private $eventManager;

    /**
     * @var GaHelper
     */
    private $gaHelper;

    /**
     * 
     * @access public
     * @param ManagerInterface $eventManager
     * @param GaHelper $gaHelper     
     * @return void
     */
    public function __construct(
        ManagerInterface $eventManager,
        GaHelper $gaHelper
    ) {
        $this->eventManager = $eventManager;
        $this->gaHelper = $gaHelper;        
    }
    
    /**
     * Send Refund event to GA4
     *
     * @access public
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Creditmemo */
        $creditmemo = $observer->getCreditmemo();        

        /** @var \Magento\Sales\Model\Order $order */
        $order = $creditmemo->getOrder();

        if (!$this->gaHelper->isActive($order->getStoreId())) {
            return;
        }                    
        if (!$order->getData('ga_user_id')) {
            return;
        }   

        $this->eventManager->dispatch('ga_measurement_protocol_refund_before', ['ga_event' => $this]);
        if ($this->skipEvent) {
            return;
        }

        $requiredParams = $this->gaHelper->getRequiredParameters($order->getStoreId());
        if (empty($requiredParams)) {
            return;
        }

        try {
            // Create service instance
            $ga4Service = new Service($requiredParams['api_secret'], $requiredParams['measurement_id']);

            // Create base request with required client_id
            $baseRequest = new BaseRequest($order->getData('ga_user_id'));

            // Create Event Data
            $refundEventData = new RefundEvent();
            $refundEventData
                ->setValue($creditmemo->getBaseGrandTotal() - $creditmemo->getBaseTaxAmount())
                ->setTransactionId($order->getIncrementId())
                ->setCurrency($creditmemo->getOrderCurrencyCode())
                ->setAffiliation($order->getStoreName())                
                ->setShipping($creditmemo->getBaseShippingAmount())
                ->setTax($creditmemo->getBaseTaxAmount());

            if (($sessionId = $order->getData('ga_session_id')) !== null){
                $refundEventData->setParamValue('session_id', $sessionId);
            }
            
            if (($sessionNumber = $order->getData('ga_session_number')) !== null){
                $refundEventData->setParamValue('session_number', $sessionNumber);
            }

            // Create Items
            foreach ($creditmemo->getAllItems() as $item) {                                
                $orderItem = $item->getOrderItem();

                if (!$item->isDeleted() && !$orderItem->getParentItemId()) {
                    $refundItem = new ItemParameter();
                    $refundItem
                        ->setItemId($item->getSku())
                        ->setItemName($item->getName())
                        ->setPrice($item->getBasePrice())
                        ->setQuantity($item->getQty());

                    if ($item->getBaseDiscountAmount()) {
                        $refundItem->setDiscount($item->getBaseDiscountAmount());
                    }

                    // Add this item to refundEventData
                    $refundEventData->addItem($refundItem);
                }
            }

            // Add event to base request (you can add up to 25 events to single request)
            $baseRequest->addEvent($refundEventData);

            // We have all the data we need. Just send the request.
            if ($this->gaHelper->isDebugMode($order->getStoreId())) {
                $debugResponse = $ga4Service->sendDebug($baseRequest);
                $statusCode = $debugResponse->getStatusCode();
                $responseBody = $debugResponse->getBody();
                $this->gaHelper->logMessage('Sending data to GA4 endpoint: ' . $ga4Service->getEndpoint(true));
                $msg = 'GaMeasurementProtocol DEBUG response -> statusCode: ' . $statusCode . ', responseBody: ' . $responseBody;
                $this->gaHelper->logMessage($msg);
            }        
            else {
                $ga4Service->send($baseRequest);
                $this->gaHelper->logMessage('Sending data to GA4 endpoint: ' . $ga4Service->getEndpoint());
                $msg = 'GaMeasurementProtocol GA4 - This data was sent for Refund event ' . json_encode($baseRequest->export());
                $this->gaHelper->logMessage($msg);                
            }
        } catch (\Exception $ex) {
            $msg = 'Error in GaMeasurementProtocol Refund Event. Exception message: ' . $ex->getMessage();
            $this->gaHelper->logMessage($msg);
            $this->gaHelper->logMessage($ex->getTraceAsString());
        }
    }

    /**
     * Set $this->skipEvent flag
     * 
     * @access public
     * @param bool $skipEvent
     */
    public function setSkipEvent($skipEvent)
    {
        $this->skipEvent = $skipEvent;
    }
}