<?php



namespace Keboola\Test\Common;

use Exception;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Options\TokenAbstractOptions;
use Keboola\StorageApi\Options\TokenCreateOptions;
use Keboola\Test\StorageApiTestCase;

class TriggersTest extends StorageApiTestCase
{
    /**
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $this->_initEmptyTestBuckets();

        $triggers = $this->_client->listTriggers();
        foreach ($triggers as $trigger) {
            $this->_client->deleteTrigger((int) $trigger['id']);
        }
    }

    /**
     * @return void
     */
    public function testCreateTrigger()
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);
        $newToken = $this->tokens->createToken($options);
        $trigger = $this->_client->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table1,
            ],
        ]);

        $this->assertEquals('orchestrator', $trigger['component']);
        $this->assertEquals(123, $trigger['configurationId']);
        $this->assertEquals(10, $trigger['coolDownPeriodMinutes']);
        $this->assertEquals($newToken['id'], $trigger['runWithTokenId']);
        $this->assertNotNull($trigger['lastRun']);
        $this->assertLessThan((new \DateTime()), (new \DateTime($trigger['lastRun'])));
        $this->assertEquals(
            [
                ['tableId' => 'in.c-API-tests.watched-1'],
            ],
            $trigger['tables']
        );
        $token = $this->_client->verifyToken();
        $this->assertEquals(
            [
                'id' => $token['id'],
                'description' => $token['description'],
            ],
            $trigger['creatorToken']
        );
    }

    /**
     * @return void
     */
    public function testCreateTriggerAsNonAdminButWithMasterTokenAsTokenRunWith()
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $options = (new TokenCreateOptions())
            ->setCanManageBuckets(true);
        $newToken = $this->tokens->createToken($options);
        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newToken['token']]);
        try {
            $clientWithoutAdminToken->createTrigger([
                'component' => 'orchestrator',
                'configurationId' => 123,
                'coolDownPeriodMinutes' => 10,
                // using master token as runWithTokenId but client's token isn't master
                'runWithTokenId' => $this->_client->verifyToken()['id'],
                'tableIds' => [
                    $table1,
                ],
            ]);
            self::fail("should fail");
        } catch (ClientException $e) {
            $this->assertEquals(
                "The 'runByToken' cannot be admin's token when your main token is not admin's",
                $e->getMessage()
            );
        }
    }

    /**
     * @dataProvider tokenCreateOptionsProvider
     * @return void
     */
    public function testCreateTriggerWithExtraPermissions(TokenCreateOptions $optionsForMainToken)
    {
        $table1 = $this->createTableWithRandomData("watched-1");

        $optionsForTokenRunWith = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $tokenRunWith = $this->tokens->createToken($optionsForTokenRunWith);
        $newNonAdminToken = $this->tokens->createToken($optionsForMainToken);

        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminToken['token']]);
        $trigger = $clientWithoutAdminToken->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $tokenRunWith['id'],
            'tableIds' => [
                $table1,
            ],
        ]);

        $this->assertEquals('orchestrator', $trigger['component']);
        $this->assertEquals(123, $trigger['configurationId']);
        $this->assertEquals(10, $trigger['coolDownPeriodMinutes']);
        $this->assertEquals($tokenRunWith['id'], $trigger['runWithTokenId']);
        $this->assertNotNull($trigger['lastRun']);
        $this->assertLessThan((new \DateTime()), (new \DateTime($trigger['lastRun'])));
        $this->assertEquals(
            [
                ['tableId' => 'in.c-API-tests.watched-1'],
            ],
            $trigger['tables']
        );
        $token = $clientWithoutAdminToken->verifyToken();
        $this->assertEquals(
            [
                'id' => $token['id'],
                'description' => $token['description'],
            ],
            $trigger['creatorToken']
        );
    }

    /**
     * @dataProvider tokenOptionsProviderInvalid
     * @param TokenCreateOptions $optionsForMainToken
     * @param string $expectedException
     * @return void
     */
    public function testCreateTriggerWithWrongPermissions(TokenCreateOptions $optionsForMainToken, $expectedException)
    {
        $table1 = $this->createTableWithRandomData("watched-1");

        $optionsForTokenRunWith = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        try {
            $tokenRunWith = $this->tokens->createToken($optionsForTokenRunWith);
            $newNonAdminToken = $this->tokens->createToken($optionsForMainToken);

            $clientWithoutAdminToken = $this->getClient([
                'url' => STORAGE_API_URL,
                'token' => $newNonAdminToken['token'],
            ]);
            $clientWithoutAdminToken->createTrigger([
                'component' => 'orchestrator',
                'configurationId' => 123,
                'coolDownPeriodMinutes' => 10,
                'runWithTokenId' => $tokenRunWith['id'],
                'tableIds' => [
                    $table1,
                ],
            ]);
            self::fail('should fail before');
        } catch (\Exception $e) {
            self::assertEquals($expectedException, $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testCreateTriggerWithMasterTokensEveryWhere()
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $myTokenId = $this->_client->verifyToken()['id'];
        $trigger = $this->_client->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $myTokenId,
            'tableIds' => [
                $table1,
            ],
        ]);

        $this->assertEquals('orchestrator', $trigger['component']);
        $this->assertEquals(123, $trigger['configurationId']);
        $this->assertEquals(10, $trigger['coolDownPeriodMinutes']);
        $this->assertEquals($myTokenId, $trigger['runWithTokenId']);
        $this->assertEquals([['tableId' => 'in.c-API-tests.watched-1']], $trigger['tables']);

        $updateData = [
            'component' => 'keboola.ex-1',
            'configurationId' => 111,
            'coolDownPeriodMinutes' => 20,
            'runWithTokenId' => $myTokenId,
            'tableIds' => [$table1],
        ];
        $updatedTrigger = $this->_client->updateTrigger((int) $trigger['id'], $updateData);
        $this->assertEquals('keboola.ex-1', $updatedTrigger['component']);
        $this->assertEquals(111, $updatedTrigger['configurationId']);
        $this->assertEquals(20, $updatedTrigger['coolDownPeriodMinutes']);
        $this->assertEquals($myTokenId, $updatedTrigger['runWithTokenId']);
        $this->assertEquals([['tableId' => 'in.c-API-tests.watched-1']], $updatedTrigger['tables']);
    }

    /**
     * @return void
     */
    public function testUpdateTriggerWithMasterToken()
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $table2 = $this->createTableWithRandomData("watched-2");

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $newToken = $this->tokens->createToken($options);

        $trigger = $this->_client->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table1,
                $table2,
            ],
        ]);

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $brandNewToken = $this->tokens->createToken($options);

        $updateData = [
            'component' => 'keboola.ex-1',
            'configurationId' => 111,
            'coolDownPeriodMinutes' => 20,
            'runWithTokenId' => $brandNewToken['id'],
            'tableIds' => [$table1],
        ];

        $newNonAdminToken = $this->tokens->createToken((new TokenCreateOptions())->setCanManageBuckets(true));
        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminToken['token']]);

        // try to update the trigger with non-master token
        try {
            $clientWithoutAdminToken->updateTrigger((int) $trigger['id'], $updateData);
            self::fail('should fail');
        } catch (Exception $e) {
            self::assertEquals('Insufficient privilege. This token cannot manipulate with this trigger', $e->getMessage());
        }

        $updateTrigger = $this->_client->updateTrigger((int) $trigger['id'], $updateData);

        $this->assertEquals('keboola.ex-1', $updateTrigger['component']);
        $this->assertEquals(111, $updateTrigger['configurationId']);
        $this->assertEquals(20, $updateTrigger['coolDownPeriodMinutes']);
        $this->assertEquals($brandNewToken['id'], $updateTrigger['runWithTokenId']);
        $this->assertEquals([['tableId' => 'in.c-API-tests.watched-1']], $updateTrigger['tables']);
    }

    /**
     * @dataProvider tokenCreateOptionsProvider
     * @return void
     */
    public function testUpdateTriggerWithNonMasterToken(TokenCreateOptions $options)
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $table2 = $this->createTableWithRandomData("watched-2");

        $optionsForTokenRunWith = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $tokenRunWith = $this->tokens->createToken($optionsForTokenRunWith);
        $newNonAdminToken = $this->tokens->createToken($options);
        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminToken['token']]);

        $trigger = $clientWithoutAdminToken->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $tokenRunWith['id'],
            'tableIds' => [
                $table1,
                $table2,
            ],
        ]);

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $brandNewToken = $this->tokens->createToken($options);

        // check update trigger as non-master but my runWithToken is master
        try {
            $updateData = [
                'component' => 'keboola.ex-1',
                'configurationId' => 111,
                'coolDownPeriodMinutes' => 20,
                'runWithTokenId' => $this->_client->verifyToken()['id'],
                'tableIds' => [$table1],
            ];

            $clientWithoutAdminToken->updateTrigger((int) $trigger['id'], $updateData);
            self::fail("should fail");
        } catch (ClientException $e) {
            $this->assertEquals(
                "The 'runByToken' cannot be admin's token when your main token is not admin's",
                $e->getMessage()
            );
        }

        $updateData = [
            'component' => 'keboola.ex-1',
            'configurationId' => 111,
            'coolDownPeriodMinutes' => 20,
            'runWithTokenId' => $brandNewToken['id'],
            'tableIds' => [$table1],
        ];

        $updatedTrigger = $clientWithoutAdminToken->updateTrigger((int) $trigger['id'], $updateData);

        $this->assertEquals('keboola.ex-1', $updatedTrigger['component']);
        $this->assertEquals(111, $updatedTrigger['configurationId']);
        $this->assertEquals(20, $updatedTrigger['coolDownPeriodMinutes']);
        $this->assertEquals($brandNewToken['id'], $updatedTrigger['runWithTokenId']);
        $this->assertEquals([['tableId' => 'in.c-API-tests.watched-1']], $updatedTrigger['tables']);

        // try it even with non-master token but this token didnt create this trigger
        $anotherToken = $this->tokens->createToken((new TokenCreateOptions())->setCanManageBuckets(true));
        $anotherClientWithAnotherToken = $this->getClient([
            'url' => STORAGE_API_URL,
            'token' => $anotherToken['token'],
        ]);

        try {
            $anotherClientWithAnotherToken->updateTrigger((int) $trigger['id'], $updateData);
            self::fail('should fail');
        } catch (Exception $e) {
            self::assertEquals('Insufficient privilege. This token cannot manipulate with this trigger', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testUpdateTriggerWithDifferentNonMasterToken()
    {
        $table1 = $this->createTableWithRandomData("watched-1");

        $optionsForTokenRunWith = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $tokenRunWith = $this->tokens->createToken($optionsForTokenRunWith);
        $newNonAdminToken = $this->tokens->createToken((new TokenCreateOptions())->setCanManageBuckets(true));
        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminToken['token']]);

        $trigger = $clientWithoutAdminToken->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $tokenRunWith['id'],
            'tableIds' => [$table1],
        ]);

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $brandNewToken = $this->tokens->createToken($options);

        $updateData = [
            'component' => 'keboola.ex-1',
            'configurationId' => 111,
            'coolDownPeriodMinutes' => 20,
            'runWithTokenId' => $brandNewToken['id'],
            'tableIds' => [$table1],
        ];

        $anotherToken = $this->tokens->createToken((new TokenCreateOptions())->setCanManageBuckets(true));
        $anotherClientWithAnotherToken = $this->getClient([
            'url' => STORAGE_API_URL,
            'token' => $anotherToken['token'],
        ]);

        try {
            $anotherClientWithAnotherToken->updateTrigger((int) $trigger['id'], $updateData);
            self::fail('should fail');
        } catch (Exception $e) {
            self::assertEquals('Insufficient privilege. This token cannot manipulate with this trigger', $e->getMessage());
        }

        // master token can do anything
        $updatedTrigger = $this->_client->updateTrigger((int) $trigger['id'], $updateData);
        self::assertEquals('keboola.ex-1', $updatedTrigger['component']);
        self::assertEquals(111, $updatedTrigger['configurationId']);
        self::assertEquals(20, $updatedTrigger['coolDownPeriodMinutes']);
        self::assertEquals($brandNewToken['id'], $updatedTrigger['runWithTokenId']);
        self::assertEquals([['tableId' => 'in.c-API-tests.watched-1']], $updatedTrigger['tables']);
    }

    /**
     * @return void
     */
    public function testUpdateTwoTables()
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $table2 = $this->createTableWithRandomData("watched-2");

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);
        $newToken = $this->tokens->createToken($options);

        $trigger1ConfigurationId = time();
        $componentName = uniqid('test', true);
        $trigger1Config = [
            'component' => $componentName,
            'configurationId' => $trigger1ConfigurationId,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table1,
            ],
        ];
        $trigger1 = $this->_client->createTrigger($trigger1Config);
        $trigger2Config = [
            'component' => 'keboola.ex-manzelka',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table2,
            ],
        ];
        $trigger2 = $this->_client->createTrigger($trigger2Config);

        $trigger1 = $this->_client->updateTrigger($trigger1['id'], $trigger1Config);
        $trigger2 = $this->_client->updateTrigger($trigger2['id'], $trigger2Config);

        $triggers = $this->_client->listTriggers();
        $trigger1Found = $trigger2Found = false;
        foreach ($triggers as $trigger) {
            if ($trigger1['id'] === $trigger['id']) {
                $trigger1Found = true;
                $this->assertSame($trigger1Config['tableIds'][0], $trigger['tables'][0]['tableId']);
            }
            if ($trigger2['id'] === $trigger['id']) {
                $trigger2Found = true;
                $this->assertSame($trigger2Config['tableIds'][0], $trigger['tables'][0]['tableId']);
            }
        }

        $this->assertTrue($trigger1Found);
        $this->assertTrue($trigger2Found);
    }

    /**
     * @dataProvider tokenCreateOptionsProvider
     * @return void
     */
    public function testDeleteTriggerCreatedByMasterToken(TokenCreateOptions $optionForToken)
    {
        $table = $this->createTableWithRandomData("watched-2");

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $newToken = $this->tokens->createToken($options);

        $trigger = $this->_client->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table,
            ],
        ]);

        $trigger2 = $this->_client->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table,
            ],
        ]);

        $newNonAdminTokenWithoutPermission = $this->tokens->createToken((new TokenCreateOptions()));
        $clientWithoutAdminTokenWithoutPermission = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminTokenWithoutPermission['token']]);

        // try to delete the trigger with non-master token without required permission
        try {
            $clientWithoutAdminTokenWithoutPermission->deleteTrigger((int) $trigger['id']);
            self::fail('should fail');
        } catch (Exception $e) {
            self::assertEquals('Your token does not have sufficient privilege.', $e->getMessage());
        }

        $anotherToken = $this->tokens->createToken($optionForToken);
        $anotherClientWithAnotherToken = $this->getClient([
            'url' => STORAGE_API_URL,
            'token' => $anotherToken['token'],
        ]);

        // trigger can be deleted by non-master token with right privilege
        $anotherClientWithAnotherToken->deleteTrigger((int) $trigger['id']);
        $triggerList = $this->_client->listTriggers([$table]);
        $this->assertNotContainsTriggerId($trigger['id'], $triggerList);
        $this->assertCount(1, $triggerList);

        $this->_client->deleteTrigger((int) $trigger2['id']);
        $triggerList = $this->_client->listTriggers([$table]);
        $this->assertNotContainsTriggerId($trigger2['id'], $triggerList);

        $this->assertCount(0, $triggerList);
    }

    /**
     * @dataProvider tokenCreateOptionsProvider
     * @return void
     */
    public function testDeleteTriggerCreatedByNonMasterTokenDifferentToken(TokenCreateOptions $optionForToken)
    {
        $table1 = $this->createTableWithRandomData("watched-1");

        $optionsForTokenRunWith = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);
        $optionsForMainToken = (new TokenCreateOptions())->setCanManageBuckets(true);

        $tokenRunWith = $this->tokens->createToken($optionsForTokenRunWith);
        $newNonAdminToken = $this->tokens->createToken($optionsForMainToken);
        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminToken['token']]);

        $trigger = $clientWithoutAdminToken->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $tokenRunWith['id'],
            'tableIds' => [$table1],
        ]);
        $trigger2 = $clientWithoutAdminToken->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $tokenRunWith['id'],
            'tableIds' => [$table1],
        ]);

        $newNonAdminTokenWithoutPermission = $this->tokens->createToken((new TokenCreateOptions()));
        $clientWithoutAdminTokenWithoutPermission = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminTokenWithoutPermission['token']]);

        // try to delete the trigger with non-master token without required permission
        try {
            $clientWithoutAdminTokenWithoutPermission->deleteTrigger((int) $trigger['id']);
            self::fail('should fail');
        } catch (Exception $e) {
            self::assertEquals('Your token does not have sufficient privilege.', $e->getMessage());
        }

        $anotherToken = $this->tokens->createToken($optionForToken);
        $anotherClientWithAnotherToken = $this->getClient([
            'url' => STORAGE_API_URL,
            'token' => $anotherToken['token'],
        ]);

        // trigger can be deleted by non-master token with right privilege
        $anotherClientWithAnotherToken->deleteTrigger((int) $trigger['id']);
        $triggerList = $clientWithoutAdminToken->listTriggers([$table1]);
        $this->assertNotContainsTriggerId($trigger['id'], $triggerList);
        $this->assertCount(1, $triggerList);

        // master token can delete it anyway
        $this->_client->deleteTrigger((int) $trigger2['id']);
        $triggerList = $clientWithoutAdminToken->listTriggers([$table1]);
        $this->assertNotContainsTriggerId($trigger2['id'], $triggerList);

        $this->assertCount(0, $triggerList);
    }

    /**
     * @dataProvider tokenCreateOptionsProvider
     * @return void
     */
    public function testDeleteTriggerCreatedByNonMasterToken(TokenCreateOptions $optionForToken)
    {
        $table1 = $this->createTableWithRandomData("watched-1");

        $optionsForTokenRunWith = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $tokenRunWith = $this->tokens->createToken($optionsForTokenRunWith);
        $newNonAdminToken = $this->tokens->createToken($optionForToken);
        $clientWithoutAdminToken = $this->getClient(['url' => STORAGE_API_URL, 'token' => $newNonAdminToken['token']]);

        $trigger = $clientWithoutAdminToken->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $tokenRunWith['id'],
            'tableIds' => [$table1],
        ]);

        // trigger can be deleted by non-master token with right privilege
        $clientWithoutAdminToken->deleteTrigger((int) $trigger['id']);
        $triggerList = $clientWithoutAdminToken->listTriggers([$table1]);
        $this->assertNotContainsTriggerId($trigger['id'], $triggerList);
        $this->assertCount(0, $triggerList);
    }

    /**
     * @param int $triggerId
     * @param array $triggerList
     * @return void
     */
    private function assertNotContainsTriggerId($triggerId, $triggerList)
    {
        $this->assertFalse(in_array($triggerId, array_map(function ($trigger) {
            return $trigger['id'];
        }, $triggerList)));
    }

    /**
     * @dataProvider deleteKeyProvider
     * @param string $keyToDelete
     * @return void
     */
    public function testMissingParameters($keyToDelete)
    {
        $data = [
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => 'nothing-is-here',
            'tableIds' => ['nothing-is-here'],
        ];
        unset($data[$keyToDelete]);
        $this->expectExceptionMessage(sprintf('Missing required query parameter(s) "%s"', $keyToDelete));
        $this->expectException(ClientException::class);
        $this->_client->createTrigger($data);
    }

    /**
     * @return void
     */
    public function testListAction()
    {
        $table = $this->createTableWithRandomData("watched-2");

        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);

        $newToken = $this->tokens->createToken($options);

        $trigger1ConfigurationId = time();
        $componentName = uniqid('test', true);
        $trigger1 = $this->_client->createTrigger([
            'component' => $componentName,
            'configurationId' => $trigger1ConfigurationId,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table,
            ],
        ]);
        $trigger2 = $this->_client->createTrigger([
            'component' => 'keboola.ex-manzelka',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table,
            ],
        ]);

        $triggers = $this->_client->listTriggers();
        $trigger1Found = $trigger2Found = false;
        foreach ($triggers as $trigger) {
            if ($trigger1['id'] === $trigger['id']) {
                $trigger1Found = true;
            }
            if ($trigger2['id'] === $trigger['id']) {
                $trigger2Found = true;
            }
        }

        $this->assertTrue($trigger1Found);
        $this->assertTrue($trigger2Found);

        $triggers = $this->_client->listTriggers(
            [
                'component' => $componentName,
                'configurationId' => $trigger1ConfigurationId
            ]
        );

        $this->assertCount(1, $triggers);
        $this->assertEquals($trigger1['id'], $triggers[0]['id']);
    }

    /**
     * @return void
     */
    public function testInvalidToken()
    {
        $token = $this->tokens->createToken(new TokenCreateOptions());
        $this->tokens->dropToken($token['id']);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage("Token with id \"{$token['id']}\" was not found.");
        $this->_client->createTrigger([
            'component' => 'keboola.ex-manzelka',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $token['id'],
            'tableIds' => [''],
        ]);
    }

    /**
     * @return void
     */
    public function testPreventTokenDelete()
    {
        $table1 = $this->createTableWithRandomData("watched-1");
        $options = (new TokenCreateOptions())
            ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ);
        $newToken = $this->tokens->createToken($options);
        $trigger = $this->_client->createTrigger([
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table1,
            ],
        ]);
        try {
            $this->tokens->dropToken($newToken['id']);
            $this->fail("Token should not be deleted");
        } catch (ClientException $e) {
            $this->assertEquals(400, $e->getCode());
            $this->assertEquals('storage.tokens.cannotDeleteDueToOrchestration', $e->getStringCode());
            $this->assertEquals(
                'Cannot delete token, bacause it\'s used for event trigger inside component "orchestrator" with configuration id "123"',
                $e->getMessage()
            );
        }
        $this->_client->deleteTrigger($trigger['id']);
        $this->tokens->dropToken($newToken['id']);
    }

    /**
     * @return void
     */
    public function testTokenWithExpiration()
    {
        $token = $this->tokens->createToken(
            (new TokenCreateOptions())->setExpiresIn(5)
        );

        $this->expectExceptionCode(400);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage("The 'runByToken' has expiration set. Use token without expiration.");
        $this->_client->createTrigger([
            'component' => 'keboola.ex-manzelka',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $token['id'],
            'tableIds' => [''],
        ]);
    }

    /**
     * @return void
     */
    public function testTriggersRestrictionsForReadOnlyUser()
    {
        $expectedError = 'Trigger manipulation is restricted for your user role "readOnly".';
        $readOnlyClient = $this->getClientForToken(STORAGE_API_READ_ONLY_TOKEN);

        $table1 = $this->createTableWithRandomData("watched-1");
        $newToken = $this->tokens->createToken(
            (new TokenCreateOptions())
                ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ)
        );

        $options = [
            'component' => 'orchestrator',
            'configurationId' => 123,
            'coolDownPeriodMinutes' => 10,
            'runWithTokenId' => $newToken['id'],
            'tableIds' => [
                $table1,
            ],
        ];

        $trigger = $this->_client->createTrigger($options);
        $triggers = $this->_client->listTriggers();

        $this->assertSame($triggers, $readOnlyClient->listTriggers());

        try {
            $readOnlyClient->createTrigger($options);
            $this->fail('Triggers API POST request should be restricted for readOnly user');
        } catch (ClientException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('accessDenied', $e->getStringCode());
            $this->assertSame($expectedError, $e->getMessage());
        }

        try {
            $readOnlyClient->updateTrigger($trigger['id'], ['configurationId' => 987]);
            $this->fail('Triggers API PUT request should be restricted for readOnly user');
        } catch (ClientException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('accessDenied', $e->getStringCode());
            $this->assertSame($expectedError, $e->getMessage());
        }

        try {
            $readOnlyClient->deleteTrigger($trigger['id']);
            $this->fail('Triggers request should be restricted for readOnly user');
        } catch (ClientException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('accessDenied', $e->getStringCode());
            $this->assertSame($expectedError, $e->getMessage());
        }

        $this->assertSame($triggers, $this->_client->listTriggers());
    }

    /**
     * @return array
     */
    public function tokenCreateOptionsProvider()
    {
        // run setup manually to create bucket IDs
        $this->setUp();
        return [
            'can manage buckets only' => [
                (new TokenCreateOptions())
                    ->setCanManageBuckets(true),
            ],
            'component access only' => [
                (new TokenCreateOptions())
                    ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ)
                    ->addComponentAccess('keboola.orchestrator'),
            ],
            'both' => [
                (new TokenCreateOptions())
                    ->setCanManageBuckets(true)
                    ->addComponentAccess('keboola.orchestrator'),
            ],
            'access to wrong component but canManageBuckets' => [
                (new TokenCreateOptions())
                    ->setCanManageBuckets(true)
                    ->addComponentAccess('keboola.invalid'),
            ],
        ];
    }

    /**
     * @return array
     */
    public function tokenOptionsProviderInvalid()
    {
        $this->setUp();
        return [
            'component access only but cannot read bucket' => [
                (new TokenCreateOptions())
                    ->addComponentAccess('keboola.orchestrator'),
                "You don't have access to the resource.",
            ],
            'access to wrong component only' => [
                (new TokenCreateOptions())
                    ->addBucketPermission($this->getTestBucketId(), TokenAbstractOptions::BUCKET_PERMISSION_READ)
                    ->addComponentAccess('keboola.invalid'),
                'Your token does not have sufficient privilege.',
            ],
            'no extra permissions, but not master token' => [
                (new TokenCreateOptions()),
                'Your token does not have sufficient privilege.',
            ],
        ];
    }

    /**
     * @return array
     */
    public function deleteKeyProvider()
    {
        return [
            'component' => ['component'],
            'configurationId' => ['configurationId'],
            'coolDownPeriodMinutes' => ['coolDownPeriodMinutes'],
            'runWithTokenId' => ['runWithTokenId'],
            'tableIds' => ['tableIds'],
        ];
    }
}
