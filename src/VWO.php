<?php

/**
 * Copyright 2019-2020 Wingify Software Pvt. Ltd.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace vwo;

use Exception as Exception;
use vwo\Constants\Constants as Constants;
use vwo\Constants\Urls as UrlConstants;
use vwo\Constants\CampaignTypes;
use vwo\Constants\LogMessages as LogMessages;
use vwo\Storage\UserStorageInterface;
use vwo\Utils\Campaign as CampaignUtil;
use vwo\Utils\Common as CommonUtil;
use vwo\Utils\Validations as ValidationsUtil;
use vwo\Utils\ImpressionBuilder as ImpressionBuilder;
use vwo\Utils\EventDispatcher as EventDispatcher;
use Monolog\Logger as Logger;
use vwo\Logger\LoggerInterface;
use vwo\Services\LoggerService as LoggerService;
use vwo\Logger\VWOLogger as VWOLogger;
use vwo\Core\Bucketer as Bucketer;
use vwo\Core\VariationDecider as VariationDecider;

/***
 * Class for exposing various APIs
 */
class VWO
{
    /****
     * @var static variables for log levels
     */

    // Levels are as per monolog docs - https://github.com/Seldaek/monolog/blob/master/doc/01-usage.md#log-levels
    static $LOG_LEVEL_DEBUG = 100;
    static $LOG_LEVEL_INFO = 200;
    static $LOG_LEVEL_WARNINGG = 300;
    static $LOG_LEVEL_ERROR = 400;

    static $apiName;

    static $_variationDecider;
    /**
     * @var mixed|string to save settings
     */
    var $settings = '';
    /**
     * @var Connection to save connection object for curl requests
     */
    // var $connection;
    /**
     * @var string to save userStorage interface object
     */

    var $_userStorageObj;
    /**
     * @var int to save if dev mode is enabled or not
     */
    var $isDevelopmentMode;

    /**
     * VWO constructor.
     *
     * @param  $config
     * @throws Exception
     */
    function __construct($config)
    {
        self::$apiName = 'init';
        if (!is_array($config)) {
            return (object)[];
        }
        // is settings and logger files are provided then set the values to the object
        $settings = isset($config['settingsFile']) ? $config['settingsFile'] : '';
        $logger = isset($config['logging']) ? $config['logging'] : null;

        // dev mode enable wont send tracking hits to the servers
        $this->isDevelopmentMode = (isset($config['isDevelopmentMode']) && $config['isDevelopmentMode'] == 1) ? 1 : 0;

        $this->eventDispatcher = new EventDispatcher($this->isDevelopmentMode);

        if ($logger == null) {
            $_logger = new VWOLogger(Logger::DEBUG, 'php://stdout');

            LoggerService::setLogger($_logger);
        } elseif ($logger instanceof LoggerInterface) {
            LoggerService::setLogger($logger);
            LoggerService::log(Logger::DEBUG, LogMessages::DEBUG_MESSAGES['CUSTOM_LOGGER_USED']);
        }

        // user storage service
        if (isset($config['userStorageService']) && ($config['userStorageService'] instanceof UserStorageInterface)) {
            $this->_userStorageObj = $config['userStorageService'];
        } else {
            $this->_userStorageObj = '';
        }

        // initial logging started for each new object
        LoggerService::log(
            Logger::DEBUG,
            LogMessages::DEBUG_MESSAGES['SET_DEVELOPMENT_MODE'],
            ['{devmode}' => $this->isDevelopmentMode]
        );

        $res = ValidationsUtil::checkSettingSchema($settings);
        if ($res) {
            $this->settings = CampaignUtil::makeRanges($settings);
        }

        // $this->connection = new Connection();
        LoggerService::log(Logger::DEBUG, LogMessages::DEBUG_MESSAGES['SDK_INITIALIZED']);

        $this->variationDecider = new VariationDecider();
        return $this;
    }

    /**
     * @param  $accountId
     * @param  $sdkKey
     * @param  $isTriggeredByWebhook
     * @return bool|mixed
     */
    public static function getSettingsFile($accountId, $sdkKey, $isTriggeredByWebhook = false)
    {
        self::$apiName = 'getSettingsFile';
        LoggerService::setApiName(self::$apiName);
        try {
            $parameters = ImpressionBuilder::getSettingsFileQueryParams($accountId, $sdkKey);
            $eventDispatcher = new EventDispatcher(false);

            $url = '';
            if ($isTriggeredByWebhook) {
                $url = UrlConstants::WEBHOOK_SETTINGS_URL;
            } else {
                $url = UrlConstants::SETTINGS_URL;
            }

            $response = $eventDispatcher->send($url, $parameters);

            return $response;
        } catch (Exception $e) {
            LoggerService::log(Logger::ERROR, $e->getMessage());
        }
        return false;
    }

    /**
     * @param  $campaignKey
     * @param  $userId
     * @param  $options
     * @return bool|null
     */
    public function isFeatureEnabled($campaignKey, $userId, $options = [])
    {
        self::$apiName = 'isFeatureEnabled';
        LoggerService::setApiName(self::$apiName);

        try {
            LoggerService::log(
                Logger::INFO,
                LogMessages::INFO_MESSAGES['API_CALLED'],
                ['{api}' => 'isFeatureEnabled', '{userId}' => $userId]
            );

            if (
                !ValidationsUtil::validateIsFeatureEnabledParams($campaignKey, $userId) ||
                !ValidationsUtil::checkSettingSchema($this->settings)
            ) {
                return null;
            }
            // get campaigns
            $campaign = ValidationsUtil::getCampaignFromCampaignKey($campaignKey, $this->settings);
            if ($campaign !== null && $campaign['type'] == CampaignTypes::AB) {
                LoggerService::log(
                    Logger::ERROR,
                    LogMessages::ERROR_MESSAGES['INVALID_CAMPAIGN_FOR_API'],
                    ['{api}' => 'isFeatureEnabled', '{campaignType}' => $campaign['type'], '{userId}' => $userId]
                );
                return null;
            }

            $result['response'] = false;
            $variationData = $this->variationDecider->fetchVariationData($this->_userStorageObj, $campaign, $userId, $options, self::$apiName);
            // below condition says that if bucket is there and isFeatureEnabled is not present it means it will be feature rollout type campaign and return true
            // if isFeatureEnabled is there and it must be true then result is true
            // else return to false
            $result['response'] = ((isset($variationData) && !isset($variationData['isFeatureEnabled'])) || (isset($variationData['isFeatureEnabled']) && $variationData['isFeatureEnabled']) == true) ? true : false;

            $parameters = ImpressionBuilder::getVisitorQueryParams(
                $this->settings['accountId'],
                $campaign,
                $userId,
                $variationData['id']
            );

            if (isset($variationData) && $result['response'] == false) {
                LoggerService::log(
                    Logger::INFO,
                    LogMessages::INFO_MESSAGES['FEATURE_ENABLED_FOR_USER'],
                    ['{featureKey}' => $campaignKey, '{userId}' => $userId, '{status}' => 'disabled']
                );

                if ($campaign['type'] == CampaignTypes::FEATURE_TEST) {
                    $this->eventDispatcher->send(UrlConstants::TRACK_USER_URL, $parameters);
                    LoggerService::log(
                        Logger::INFO,
                        LogMessages::INFO_MESSAGES['IMPRESSION_FOR_TRACK_USER'],
                        ['{properties}' => json_encode($parameters)]
                    );
                }

                return false;
            }
            if ($result !== false && isset($result['response']) && $result['response'] == true && isset($variationData)) {
                LoggerService::log(
                    Logger::INFO,
                    LogMessages::INFO_MESSAGES['FEATURE_ENABLED_FOR_USER'],
                    ['{featureKey}' => $campaignKey, '{userId}' => $userId, '{status}' => 'enabled']
                );

                if ($campaign['type'] != CampaignTypes::FEATURE_ROLLOUT) {
                    $this->eventDispatcher->send(UrlConstants::TRACK_USER_URL, $parameters);
                    LoggerService::log(
                        Logger::INFO,
                        LogMessages::INFO_MESSAGES['IMPRESSION_FOR_TRACK_USER'],
                        ['{properties}' => json_encode($parameters)]
                    );
                }
                return true;
            }
            return $campaign['type'] == CampaignTypes::FEATURE_ROLLOUT ? false : null;
        } catch (Exception $e) {
            LoggerService::log(Logger::ERROR, $e->getMessage());
        }

        return isset($campaign) && isset($campaign['type']) && ($campaign['type'] == CampaignTypes::FEATURE_ROLLOUT) ? false : null;
    }

    /**
     * @param  $campaignKey
     * @param  $variableKey
     * @param  $userId
     * @return bool|float|int|null|string
     */
    public function getFeatureVariableValue($campaignKey, $variableKey, $userId, $options = [])
    {
        self::$apiName = 'getFeatureVariableValue';
        LoggerService::setApiName(self::$apiName);

        try {
            if (
                !ValidationsUtil::validateIsFeatureEnabledParams($campaignKey, $userId) ||
                !ValidationsUtil::checkSettingSchema(
                    $this->settings
                )
            ) {
                return null;
            }

            $campaign = ValidationsUtil::getCampaignFromCampaignKey($campaignKey, $this->settings);
            if ($campaign != null && $campaign['type'] == CampaignTypes::AB) {
                LoggerService::log(
                    Logger::ERROR,
                    LogMessages::ERROR_MESSAGES['INVALID_API_CALL'],
                    [
                        '{api}' => 'getFeatureVariableValue',
                        '{userId}' => $userId,
                        '{campaignKey}' => $campaignKey,
                        '{campaignType}' => 'SERVER AB'
                    ]
                );
                return null;
            }
            $value = null;

            $featureData['response'] = false;
            $variationData = $this->variationDecider->fetchVariationData($this->_userStorageObj, $campaign, $userId, $options, self::$apiName);
            $featureData['variationData'] = $variationData;
            // below condition says that if bucket is there and isFeatureEnabled is not present it means it will be feature rollout type campaign and return true
            // if isFeatureEnabled is there and it must be true then result is true
            // else return to false
            $featureData['response'] = ((isset($variationData) && !isset($variationData['isFeatureEnabled'])) || (isset($variationData['isFeatureEnabled']) && $variationData['isFeatureEnabled']) == true) ? true : false;

            if ($featureData) {
                if (isset($featureData['variationData'])) {
                    if ($campaign['type'] == CampaignTypes::FEATURE_ROLLOUT) {
                        $featureVariable = $campaign['variables'];
                    } else {
                        // it is part of feature test
                        if ($featureData['response'] == 1 && isset($featureData['variationData']['variables'])) {
                            $featureVariable = $featureData['variationData']['variables'];
                        } else {
                            $featureVariable = CommonUtil::fetchControlVariation(
                                $campaign['variations']
                            )['variables'];
                        }
                    }
                    $value = CommonUtil::getVariableValue($featureVariable, $variableKey);
                }
            }
            if ($value == null) {
                LoggerService::log(
                    Logger::DEBUG,
                    LogMessages::INFO_MESSAGES['VARIABLE_NOT_FOUND'],
                    [
                        '{userId}' => $userId,
                        '{variableKey}' => $variableKey,
                        '{campaignKey}' => $campaignKey,
                        '{variableValue}' => $value
                    ]
                );
            } else {
                LoggerService::log(
                    Logger::DEBUG,
                    LogMessages::INFO_MESSAGES['VARIABLE_FOUND'],
                    [
                        '{userId}' => $userId,
                        '{variableKey}' => $variableKey,
                        '{campaignKey}' => $campaignKey,
                        '{variableValue}' => $value
                    ]
                );
            }

            return $value;
        } catch (Exception $e) {
            LoggerService::log(Logger::ERROR, $e->getMessage());
        }

        return null;
    }

    /**
     * API for track the user goals and revenueValue
     *
     * @param string $campaignKey
     * @param string $userId
     * @param string $goalIdentifier
     * @param array $options
     * @return bool|null
     */
    public function track($campaignKey = '', $userId = '', $goalIdentifier = '', array $options = [])
    {
        self::$apiName = 'track';
        LoggerService::setApiName(self::$apiName);

        try {
            $revenueValue = CommonUtil::getValueFromOptions($options, 'revenueValue');
            $bucketInfo = null;

            if (empty($campaignKey) || empty($userId) || empty($goalIdentifier)) {
                LoggerService::log(Logger::ERROR, LogMessages::ERROR_MESSAGES['TRACK_API_MISSING_PARAMS']);
                return $bucketInfo;
            }

            $campaign = ValidationsUtil::getCampaignFromCampaignKey($campaignKey, $this->settings);
            if ($campaign == null) {
                return null;
            }
            if ($campaign['type'] == CampaignTypes::FEATURE_ROLLOUT) {
                LoggerService::log(
                    Logger::ERROR,
                    LogMessages::ERROR_MESSAGES['INVALID_API_CALL'],
                    [
                        '{api}' => 'track',
                        '{userId}' => $userId,
                        '{campaignKey}' => $campaignKey,
                        '{campaignType}' => $campaign['type']
                    ]
                );
                return $bucketInfo;
            }

            $bucketInfo = $this->variationDecider->fetchVariationData($this->_userStorageObj, $campaign, $userId, $options, self::$apiName);

            if ($bucketInfo == null) {
                return $bucketInfo;
            }

            $goal = CommonUtil::getGoalFromGoals($campaign['goals'], $goalIdentifier);
            $goalId = isset($goal['id']) ? $goal['id'] : 0;
            if ($goalId && isset($bucketInfo['id']) && $bucketInfo['id'] > 0) {
                if ($goal['type'] == "REVENUE_TRACKING" && is_null($revenueValue)) {
                    LoggerService::log(
                        Logger::ERROR,
                        LogMessages::ERROR_MESSAGES['MISSING_GOAL_REVENUE'],
                        [
                            '{goalIdentifier}' => $goalIdentifier,
                            '{campaignKey}' => $campaignKey,
                            '{userId}' => $userId
                        ]
                    );
                    return null;
                }

                $parameters = ImpressionBuilder::getConversionQueryParams(
                    $this->settings['accountId'],
                    $campaign,
                    $userId,
                    $bucketInfo['id'],
                    $goal,
                    $revenueValue
                );

                $response = $this->eventDispatcher->send(UrlConstants::TRACK_GOAL_URL, $parameters);

                LoggerService::log(
                    Logger::INFO,
                    LogMessages::INFO_MESSAGES['IMPRESSION_FOR_TRACK_GOAL'],
                    array('{properties}' => json_encode($parameters))
                );

                if ($response) {
                    LoggerService::log(
                        Logger::INFO,
                        LogMessages::INFO_MESSAGES['IMPRESSION_SUCCESS'],
                        [
                            '{userId}' => $userId,
                            '{endPoint}' => 'track-goal',
                            '{campaignId}' => $campaign['id'],
                            '{variationId}' => $bucketInfo['id'],
                            '{accountId}' => $this->settings['accountId']
                        ]
                    );
                    return true;
                } elseif ($this->isDevelopmentMode) {
                    return true;
                }
            } else {
                LoggerService::log(
                    Logger::ERROR,
                    LogMessages::ERROR_MESSAGES['TRACK_API_GOAL_NOT_FOUND'],
                    ['{campaignKey}' => $campaignKey, '{userId}' => $userId]
                );

                return null;
            }
        } catch (Exception $e) {
            LoggerService::log(Logger::ERROR, $e->getMessage());
        }

        return null;
    }

    /**
     * to send variation name along with api hit to send add visitor hit
     *
     * @param  $campaignKey
     * @param  $userId
     * @param  $options
     * @return string|null
     */
    public function activate($campaignKey, $userId, $options = [])
    {
        self::$apiName = 'activate';
        LoggerService::setApiName(self::$apiName);

        LoggerService::log(
            Logger::INFO,
            LogMessages::INFO_MESSAGES['API_CALLED'],
            ['{api}' => 'activate', '{userId}' => $userId]
        );
        return $this->getVariation($campaignKey, $userId, $options, 1);
    }

    /**
     * fetch the variation name
     *
     * @param  $campaignKey
     * @param  $userId
     * @param int $trackVisitor
     * @return null|string
     */
    private function getVariation($campaignKey, $userId, $options = [], $trackVisitor = 0)
    {
        $bucketInfo = null;
        try {
            $campaign = ValidationsUtil::getCampaignFromCampaignKey($campaignKey, $this->settings);
            if ($campaign !== null) {
                if (($campaign['type'] == CampaignTypes::FEATURE_ROLLOUT) || ($campaign['type'] == CampaignTypes::FEATURE_TEST && $trackVisitor == 1)) {
                    LoggerService::log(
                        Logger::ERROR,
                        LogMessages::ERROR_MESSAGES['INVALID_API_CALL'],
                        [
                            '{api}' => $trackVisitor == 1 ? 'activate' : 'getVariationName',
                            '{userId}' => $userId,
                            '{campaignKey}' => $campaignKey,
                            '{campaignType}' => $campaign['type']
                        ]
                    );
                    return $bucketInfo;
                }
            } else {
                return $bucketInfo;
            }
            $bucketInfo = $this->variationDecider->fetchVariationData($this->_userStorageObj, $campaign, $userId, $options, $trackVisitor ? 'activate' : 'getVariationName');
            if ($bucketInfo !== null) {
                if ($trackVisitor) {
                    $parameters = ImpressionBuilder::getVisitorQueryParams(
                        $this->settings['accountId'],
                        $campaign,
                        $userId,
                        $bucketInfo['id']
                    );

                    $this->eventDispatcher->send(UrlConstants::TRACK_USER_URL, $parameters);
                    LoggerService::log(
                        Logger::INFO,
                        LogMessages::INFO_MESSAGES['IMPRESSION_FOR_TRACK_USER'],
                        ['{properties}' => json_encode($parameters)]
                    );
                }

                return $bucketInfo['name'];
            }
        } catch (Exception $e) {
            LoggerService::log(Logger::ERROR, $e->getMessage());
        }
        return null;
    }

    /**
     * to send variation name along with api hit to send add visitor hit
     *
     * @param  $campaignKey
     * @param  $userId
     * @return string|null
     */
    public function getVariationName($campaignKey, $userId, $options = [])
    {
        self::$apiName = 'getVariationName';
        LoggerService::setApiName(self::$apiName);

        LoggerService::log(
            Logger::INFO,
            LogMessages::INFO_MESSAGES['API_CALLED'],
            ['{api}' => 'getVariationName', '{userId}' => $userId]
        );

        return $this->getVariation($campaignKey, $userId, $options, 0);
    }

    /**
     * @param  $tagKey
     * @param  $tagValue
     * @param  $userId
     * @return bool
     */
    public function push($tagKey, $tagValue, $userId)
    {
        self::$apiName = 'push';
        LoggerService::setApiName(self::$apiName);

        try {
            if (
                !ValidationsUtil::pushApiParams($tagKey, $tagValue, $userId) ||
                !ValidationsUtil::checkSettingSchema($this->settings)
            ) {
                return false;
            }

            $parameters = ImpressionBuilder::getPushQueryParams($this->settings['accountId'], $userId, $tagKey, $tagValue);
            $response = $this->eventDispatcher->send(UrlConstants::PUSH_URL, $parameters);

            LoggerService::log(
                Logger::INFO,
                LogMessages::INFO_MESSAGES['IMPRESSION_FOR_PUSH'],
                ['{properties}' => json_encode($parameters)]
            );

            if ($response) {
                LoggerService::log(
                    Logger::INFO,
                    LogMessages::INFO_MESSAGES['IMPRESSION_SUCCESS_PUSH'],
                    [
                        '{userId}' => $userId,
                        '{endPoint}' => 'push',
                        '{accountId}' => $this->settings['accountId'],
                        '{tags}' => $parameters['tags']
                    ]
                );

                return true;
            } elseif ($this->isDevelopmentMode) {
                return true;
            }
            LoggerService::log(Logger::ERROR, LogMessages::ERROR_MESSAGES['IMPRESSION_FAILED'], ['{endPoint}' => 'push']);
        } catch (Exception $e) {
            LoggerService::log(Logger::ERROR, $e->getMessage());
        }

        return false;
    }
}
