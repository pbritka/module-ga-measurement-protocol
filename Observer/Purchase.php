<?php

namespace Pbritka\GaMeasurementProtocol\Observer;

use Br33f\Ga4\MeasurementProtocol\Service;
use Br33f\Ga4\MeasurementProtocol\Dto\Request\BaseRequest;
use Br33f\Ga4\MeasurementProtocol\Dto\Event\PurchaseEvent;
use Br33f\Ga4\MeasurementProtocol\Dto\Parameter\ItemParameter;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Spi\OrderResourceInterface;
use Pbritka\GaMeasurementProtocol\Helper\Ga as GaHelper;

class Purchase implements ObserverInterface
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
     * @var OrderResourceInterface
     */
    private $orderResource;
    
    /**
     * 
     * @access public
     * @param ManagerInterface $eventManager
     * @param GaHelper $gaHelper
     * @param OrderResourceInterface $orderResource
     * @return void
     */
    public function __construct(
        ManagerInterface $eventManager,
        GaHelper $gaHelper,
        OrderResourceInterface $orderResource
    ) {
        $this->eventManager = $eventManager;
        $this->gaHelper = $gaHelper;
        $this->orderResource = $orderResource;
    }
    
    /**
     * Send / cancel order in GA4
     *
     * @access public
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getOrder();        

        if (!$this->gaHelper->isActive($order->getStoreId())) {
            return;
        }
        if (!$order->getData('ga_user_id')) {            
            return;
        }
        if ($order->getStatus() != 'canceled' && $order->getData('is_in_ga4')) {
            return;
        }

        //check for custom flag
        $this->eventManager->dispatch('ga_measurement_protocol_purchase_before', ['ga_event' => $this]);
        
        if ($order->getStatus() == 'canceled' && ($order->getData('is_canceled_in_ga4') || !$order->getData('is_in_ga4'))) {
            return;
        }
        if ($order->getBaseGrandTotal() == 0) {
            return;
        }
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

            $grandTotal = $order->getBaseGrandTotal() - $order->getBaseTaxAmount();
            $shipping = $order->getBaseShippingAmount();
            $tax = $order->getBaseTaxAmount();

            if ($order->getStatus() == 'canceled') {
                $grandTotal *= -1;
                $shipping *= -1;
                $tax *= -1;
            }

            // Create Event Data
            $purchaseEventData = new PurchaseEvent();
            $purchaseEventData
                ->setValue($grandTotal)
                ->setTransactionId($order->getIncrementId())
                ->setCurrency($order->getOrderCurrencyCode())
                ->setAffiliation($order->getStoreName())                
                ->setShipping($shipping)
                ->setTax($tax);

            if (($sessionId = $order->getData('ga_session_id')) !== null){
                $purchaseEventData->setParamValue('session_id', $sessionId);
            }
            
            if (($sessionNumber = $order->getData('ga_session_number')) !== null){
                $purchaseEventData->setParamValue('session_number', $sessionNumber);
            }

            if ($order->getCouponCode()) {
                $purchaseEventData->setCoupon($order->getCouponCode());
            }

            // Create Items
            foreach ($order->getAllItems() as $orderItem) {
                if (!$orderItem->isDeleted() && !$orderItem->getParentItem()) {
                    $qty = $orderItem->getQtyOrdered();
                    if ($order->getStatus() == 'canceled') {
                        $qty *= -1;
                    }
                    
                    $purchasedItem = new ItemParameter();
                    $purchasedItem
                        ->setItemId($orderItem->getSku())
                        ->setItemName($orderItem->getName())
                        ->setPrice($orderItem->getBasePrice())
                        ->setQuantity($qty);

                    if ($orderItem->getBaseDiscountAmount() != 0) {
                        $purchasedItem->setDiscount($orderItem->getBaseDiscountAmount());
                    }

                    // Add this item to purchaseEventData
                    $purchaseEventData->addItem($purchasedItem);
                }
            }

            // Add event to base request (you can add up to 25 events to single request)
            $baseRequest->addEvent($purchaseEventData);

            // We have all the data we need. Just send the request.
            if ($this->gaHelper->isDebugMode($order->getStoreId())) {
                $debugResponse = $ga4Service->sendDebug($baseRequest);
                $statusCode = $debugResponse->getStatusCode();
                $responseBody = $debugResponse->getBody();
                $this->gaHelper->logMessage('Sending data to GA4 endpoint: ' . $ga4Service->getEndpoint(true));
                $msg = 'GaMeasurementProtocol GA4 DEBUG response -> statusCode: ' . $statusCode . ', responseBody: ' . $responseBody;
                $this->gaHelper->logMessage($msg);
            }        
            else {
                $ga4Service->send($baseRequest);
                $this->gaHelper->logMessage('Sending data to GA4 endpoint: ' . $ga4Service->getEndpoint());
                $msg = 'GaMeasurementProtocol GA4 - This data was sent for Purchase event ' . json_encode($baseRequest->export());
                $this->gaHelper->logMessage($msg);

                if (!$order->getData('is_in_ga4')) {                    
                    $order->setData('is_in_ga4', true);
                    $this->orderResource->saveAttribute($order, 'is_in_ga4');
                }
                if ($order->getStatus() == 'canceled') {                    
                    $order->setData('is_canceled_in_ga4', true);
                    $this->orderResource->saveAttribute($order, 'is_canceled_in_ga4');
                }
            }
        } catch (\Exception $ex) {
            $msg = 'Error in GaMeasurementProtocol Purchase Event. Exception message: ' . $ex->getMessage();
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
