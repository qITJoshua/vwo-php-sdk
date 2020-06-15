<?php

/**
 * Copyright 2019-2020 Wingify Software Pvt. Ltd.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace vwo;

use Exception;
use vwo\Services\LoggerService;
use vwo\Storage\UserStorageInterface;
use vwo\Logger\LoggerInterface;

class TestUtil
{
    public static function getUsers()
    {
        $users = [
            'Ashley',
            'Bill',
            'Chris',
            'Dominic',
            'Emma',
            'Faizan',
            'Gimmy',
            'Harry',
            'Ian',
            'John',
            'King',
            'Lisa',
            'Mona',
            'Nina',
            'Olivia',
            'Pete',
            'Queen',
            'Robert',
            'Sarah',
            'Tierra',
            'Una',
            'Varun',
            'Will',
            'Xin',
            'You',
            'Zeba'
        ];

        return $users;
    }

    /**
     * Source - https://jtreminio.com/blog/unit-testing-tutorial-part-iii-testing-protected-private-methods-coverage-reports-and-crap/
     *
     * Call protected/private method of a class.
     *
     * @param object &$object    Instantiated object that we will run method on.
     * @param string $methodName Method name to call
     * @param array  $parameters Array of parameters to pass into method.
     *
     * @return mixed Method return.
     */
    public static function invokePrivateMethod(&$object, $methodName, array $parameters = array())
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public static function mockEventDispatcher($obj, $status = 200)
    {
        $mockEventDispatcher = $obj->getMockBuilder('EventDispatcher')->setMethods(['send'])->getMock();
        $mockEventDispatcher->method('send')->will($obj->returnValue(['httpStatus' => $status]));

        return $mockEventDispatcher;
    }

    public static function mockMethodToThrowEception($obj, $className, $method)
    {
        $mock = $obj->getMockBuilder($className)->setMethods([$method])->getMock();
        $mock->expects($obj->any())->method($method)->will($obj->throwException(new Exception()));

        return $mock;
    }

    public static function instantiateSdk($settingsFile, $options = [])
    {
        $isDevelopmentMode = isset($options['isDevelopmentMode']) ? $options['isDevelopmentMode'] : 0;
        $config = [
            'settingsFile' => $settingsFile,
            'isDevelopmentMode' => $isDevelopmentMode,
            'logging' => new CustomLogger()
        ];

        if (isset($options['isUserStorage'])) {
            $config['userStorageService'] =  new UserStorageTest();
        }

        $sdkInstance = new VWO($config);

        return $sdkInstance;
    }
}

/**
 * Class CustomLogger
 */
class CustomLogger implements LoggerInterface
{

    /**
     * @param  $message
     * @param  $level
     * @return string
     */
    public function log($message, $level)
    {
        // echo $level . ' - ' . $message . PHP_EOL;
    }
}

class UserStorageTest implements UserStorageInterface
{

    /**
     * @param  $userId
     * @param  $campaignKey
     * @return string
     */
    public function get($userId, $campaignKey)
    {
        return [
            'userId' => $userId,
            'variationName' => 'Control',
            'campaignKey' => $campaignKey
        ];
    }

    /**
     * @param  $campaignInfo
     * @return bool
     */
    public function set($campaignInfo)
    {
        return true;
    }
}

class UserStorageGetCorruptedTest implements UserStorageInterface
{

    /**
     * @param  $userId
     * @param  $campaignKey
     * @return string
     */
    public function get($userId, $campaignKey)
    {
        return [
            'userId' => $userId,
            // 'variationName' => 'Control',
            'campaignKey' => $campaignKey
        ];
    }

    /**
     * @param  $campaignInfo
     * @return bool
     */
    public function set($campaignInfo)
    {
        return true;
    }
}