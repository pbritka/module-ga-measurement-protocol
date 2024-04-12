<?php

namespace Pbritka\GaMeasurementProtocol\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Model\Order;
use Pbritka\GaMeasurementProtocol\Helper\Ga as GaHelper;

class SaveGaSessionData implements ObserverInterface
{
    /**
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var GaHelper
     */
    private $gaHelper;

    /**
     * @access public
     * @param CookieManagerInterface $cookieManager
     * @param GaHelper $gaHelper     
     * @return void
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        GaHelper $gaHelper
    ) {
        $this->cookieManager = $cookieManager;
        $this->gaHelper = $gaHelper;
    }

    /**
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

        $requiredParams = $this->gaHelper->getRequiredParameters($order->getStoreId());
        if (empty($requiredParams)) {
            return;
        }

        $sessionData = $this->getGaSessionData($requiredParams['measurement_id']);                
        $order->setData('ga_session_id', $sessionData['id']);
        $order->setData('ga_session_number', $sessionData['number']);
        $order->setData('ga_user_id', $this->getGaUserId($order));
    }

    /**
     * Get GA session data
     * 
     * @access private
     * @param string $measurementId
     * @return array
     */
    private function getGaSessionData($measurementId = '')
    {
        $cookieName = '_ga_' . str_replace('G-', '', $measurementId);
        $cookie = $this->cookieManager->getCookie($cookieName);

        if (empty($cookie)) {
            $sessionId = random_int((int)1E5, (int)1E9);
            $this->gaHelper->logMessage('Google Analytics cookie ' . $cookieName . ' not found, generated random GA session id: ' . $sessionId);

            return [
                'id' => $sessionId,
                'number' => null
            ];
        }

        $cookieParts = explode('.', $cookie);

        return (count($cookieParts) < 4) ? [] : [
            'id' => $cookieParts[2],
            'number' => $cookieParts[3],
        ];
    }

    /**
     * Get Google Analytics User ID
     * 
     * @access private
     * @param Order $order
     * @return string
     */
    private function getGaUserId($order)
    {
        $gaUserId = $this->getGaUserIdFromCookie();
        if ($gaUserId === null) {
            $gaCookieUserId = random_int(1E8, 1E9);
            $gaCookieTimestamp = time();
            $gaUserId = implode('.', [$gaCookieUserId, $gaCookieTimestamp]);            
            $this->gaHelper->logMessage('Google Analytics cookie for order ' . $order->getIncrementId() . ' not found, generated temporary value: ' . $gaUserId);            
        }

        return $gaUserId;
    }
    
    /**
     * Try to get the Google Analytics User ID from the cookie
     *
     * @return string|null
     */
    private function getGaUserIdFromCookie()
    {
        $gaCookie = explode('.', $this->cookieManager->getCookie('_ga') ?? '');

        if (empty($gaCookie) || count($gaCookie) < 4) {
            return null;
        }

        list(
            $gaCookieVersion,
            $gaCookieDomainComponents,
            $gaCookieUserId,
            $gaCookieTimestamp
            ) = $gaCookie;

        if (!$gaCookieUserId || !$gaCookieTimestamp) {
            return null;
        }        

        if ($gaCookieVersion != 'GA' . GaHelper::API_VERSION) {
            $this->gaHelper->logMessage('Google Analytics cookie version differs from Measurement Protocol API version; please upgrade.');
            return null;
        }

        return implode('.', [$gaCookieUserId, $gaCookieTimestamp]);
    }
}
