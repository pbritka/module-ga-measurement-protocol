<?php

namespace Pbritka\GaMeasurementProtocol\Helper;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Ga extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * Google Analytics Measurement Protocol API version
     */
    const API_VERSION = 1;

    const PATH_GA_MEASUREMENT_PROTOCOL_ACTIVE = 'google/measurement_protocol_ga4/active';

    const PATH_GA_MEASUREMENT_PROTOCOL_DEBUG_MODE = 'google/measurement_protocol_ga4/debug_mode';

    const PATH_GA_API_SECRET = 'google/measurement_protocol_ga4/api_secret';

    const PATH_GA_MEASUREMENT_ID = 'google/measurement_protocol_ga4/measurement_id';    

    /**
     * @var LoggerInterface
     */
    protected $_logger = null;    

    /**
     * __construct
     *
     * @access public     
     * @param LoggerInterface $logger
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        Context $context
    ) {        
        parent::__construct($context);
        $this->_logger = $logger;
    }

    /**
     * Is Measurement Protocol Active?
     *
     * @access public
     * @param  int $storeId
     * @return string
     */
    public function isActive($storeId = null)
    {
        return $this->scopeConfig->getValue(self::PATH_GA_MEASUREMENT_PROTOCOL_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get Measurement Protocol API secret
     *
     * @access public
     * @param int $storeId
     * @return string
     */
    public function getApiSecret($storeId = null)
    {
        return $this->scopeConfig->getValue(self::PATH_GA_API_SECRET, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get Measurement Protocol measurement ID
     *
     * @access public
     * @param int $storeId
     * @return string
     */
    public function getMeasurementId($storeId = null)
    {
        return $this->scopeConfig->getValue(self::PATH_GA_MEASUREMENT_ID, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get required parameter for API requests
     *
     * @access public
     * @param int $storeId
     * @return array
     */
    public function getRequiredParameters($storeId = null)
    {
        $apiSecret = $this->getApiSecret($storeId);
        $measurementId = $this->getMeasurementId($storeId);

        if (empty($apiSecret)) {
            $this->logMessage('API secret for Google Anaylitcs is missing - no data can be sent to measurement protocol');
            return [];
        }
        if (empty($measurementId)) {
            $this->logMessage('Measurement ID for Google Analytics is missing - no data can be sent to measurement protocol');
            return [];
        }
        
        return [
            'api_secret' => $apiSecret,
            'measurement_id' => $measurementId
        ];
    }
        
    /**
     * Is sending to measurement protocol in debug mode?
     *
     * @access public
     * @param int $storeId
     * @return string
     */
    public function isDebugMode($storeId = null)
    {
        return $this->scopeConfig->getValue(self::PATH_GA_MEASUREMENT_PROTOCOL_DEBUG_MODE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Log message to plugin file log
     * 
     * @access public
     * @param mixed $message
     * @param int $priority
     * @param array|Traversable $extra
     */
    public function logMessage($message, $priority = \Monolog\Logger::INFO, $extra = []) {
        if (is_array($message) || is_object($message)){
            $this->_logger->log($priority, print_r($message, true), $extra);
        } else {
            $this->_logger->log($priority, $message, $extra);
        }
    }
}
