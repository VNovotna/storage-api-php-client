<?php

namespace Keboola\Test\Backend\MixedSnowflakeExasol;

use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Workspaces;
use Keboola\Test\Backend\Mixed\StorageApiSharingTestCase;
use Keboola\Test\Backend\WorkspaceConnectionTrait;
use Keboola\Test\Backend\Workspaces\Backend\ExasolWorkspaceBackend;
use Keboola\Test\Backend\Workspaces\Backend\SynapseWorkspaceBackend;
use Keboola\Test\Backend\Workspaces\Backend\WorkspaceBackendFactory;

class SharingTest extends StorageApiSharingTestCase
{
    use WorkspaceConnectionTrait;

    public function sharingBackendData()
    {
        return [
            [self::BACKEND_SNOWFLAKE],
            [self::BACKEND_EXASOL],
        ];
    }

    public function workspaceMixedBackendData()
    {
        return [
            [
                'sharing backend' => self::BACKEND_SNOWFLAKE,
                'workspace backend' => self::BACKEND_SNOWFLAKE,
                'load type' => 'direct',
            ],
            [
                'sharing backend' => self::BACKEND_SNOWFLAKE,
                'workspace backend' => self::BACKEND_EXASOL,
                'load type' => 'staging',
            ],
            [
                'sharing backend' => self::BACKEND_EXASOL,
                'workspace backend' => self::BACKEND_EXASOL,
                'load type' => 'direct',
            ],
        ];
    }

    public function testOrganizationAdminInTokenVerify()
    {
        $token = $this->_client->verifyToken();
        self::assertTrue($token['admin']['isOrganizationMember']);
    }

    /**
     * @dataProvider workspaceMixedBackendData
     *
     * @param string $sharingBackend
     * @param string $workspaceBackend
     * @param string $expectedLoadType
     * @throws ClientException
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Keboola\StorageApi\Exception
     */
    public function testWorkspaceLoadData(
        $sharingBackend,
        $workspaceBackend,
        $expectedLoadType
    ) {
        //setup test tables
        $this->deleteAllWorkspaces();
        $this->initTestBuckets($sharingBackend);
        $bucketId = $this->getTestBucketId(self::STAGE_IN);
        $secondBucketId = $this->getTestBucketId(self::STAGE_OUT);

        $table1Id = $this->_client->createTableAsync(
            $bucketId,
            'languages',
            new CsvFile(__DIR__ . '/../../_data/languages.csv'),
            [
                'primaryKey' => 'name',
            ]
        );
        if ($this->isExasolTestCase($sharingBackend, $workspaceBackend)) {
            $this->assertExpectedDistributionKeyColumn($table1Id, 'name');
        }

        $table2Id = $this->_client->createTableAsync(
            $bucketId,
            'numbers',
            new CsvFile(__DIR__ . '/../../_data/numbers.csv'),
            [
                'primaryKey' => '1',
            ]
        );
        if ($this->isExasolTestCase($sharingBackend, $workspaceBackend)) {
            $this->assertExpectedDistributionKeyColumn($table2Id, '1');
        }

        $table3Id = $this->_client->createAliasTable(
            $bucketId,
            $table2Id,
            'numbers-alias'
        );
        if ($this->isExasolTestCase($sharingBackend, $workspaceBackend)) {
            $this->assertExpectedDistributionKeyColumn($table3Id, '1');
        }

        // share and link bucket
        $this->_client->shareBucket($bucketId);
        self::assertTrue($this->_client->isSharedBucket($bucketId));

        $response = $this->_client2->listSharedBuckets();
        self::assertCount(1, $response);
        $sharedBucket = reset($response);

        $linkedId = $this->_client2->linkBucket(
            "linked-" . time(),
            'out',
            $sharedBucket['project']['id'],
            $sharedBucket['id']
        );
        if ($this->isExasolTestCase($sharingBackend, $workspaceBackend)) {
            $tables = $this->_client2->listTables($linkedId);
            foreach ($tables as $table) {
                switch ($table['sourceTable']) {
                    case $table1Id:
                        $this->assertExpectedDistributionKeyColumn($table1Id, 'name');
                        break;
                    case $table2Id:
                    case $table3Id:
                        $this->assertExpectedDistributionKeyColumn($table3Id, '1');
                        break;
                }
            }
        }

        // share and unshare second bucket - test that it doesn't break permissions of first linked bucket
        $this->_client->shareBucket($secondBucketId);
        $sharedBucket2 = array_values(array_filter($this->_client->listSharedBuckets(), function ($bucket) use (
            $secondBucketId
        ) {
            return $bucket['id'] === $secondBucketId;
        }))[0];
        $linked2Id = $this->_client2->linkBucket(
            "linked-2-" . time(),
            'out',
            $sharedBucket2['project']['id'],
            $sharedBucket2['id']
        );
        $this->_client2->dropBucket($linked2Id);

        $mapping1 = [
            "source" => str_replace($bucketId, $linkedId, $table1Id),
            "destination" => "languagesLoaded",
        ];

        $mapping2 = [
            "source" => str_replace($bucketId, $linkedId, $table2Id),
            "destination" => "numbersLoaded",
        ];

        $mapping3 = [
            "source" => str_replace($bucketId, $linkedId, $table3Id),
            "destination" => "numbersAliasLoaded",
        ];

        // init workspace
        $workspaces = new Workspaces($this->_client2);
        $workspace = $workspaces->createWorkspace([
            "backend" => $workspaceBackend,
        ]);

        $input = [$mapping1, $mapping2, $mapping3];

        // test if job is created and listed
        $initialJobs = $this->_client2->listJobs();

        $runId = $this->_client2->generateRunId();
        $this->_client2->setRunId($runId);

        $workspaces->loadWorkspaceData($workspace['id'], ["input" => $input]);

        $this->createAndWaitForEvent(
            (new \Keboola\StorageApi\Event())->setComponent('dummy')->setMessage('dummy'),
            $this->_client2
        );

        $events = $this->_client2->listEvents(['runId' => $runId, 'q' => 'storage.workspaceLoaded',]);
        self::assertCount(3, $events);
        foreach ($events as $event) {
            self::assertSame($expectedLoadType, $event['results']['loadType']);
        }

        $afterJobs = $this->_client2->listJobs();

        self::assertEquals('workspaceLoad', $afterJobs[0]['operationName']);
        self::assertNotEquals(empty($initialJobs) ? 0 : $initialJobs[0]['id'], $afterJobs[0]['id']);

        // block until async events are processed, processing in order is not guaranteed but it should work most of time
        $this->createAndWaitForEvent((new \Keboola\StorageApi\Event())->setComponent('dummy')->setMessage('dummy'));

        $stats = $this->_client2->getStats((new \Keboola\StorageApi\Options\StatsOptions())->setRunId($runId));

        $export = $stats['tables']['export'];
        self::assertEquals(3, $export['totalCount']);
        self::assertCount(3, $export['tables']);

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        $tables = $backend->getTables();

        // check that the tables are in the workspace
        self::assertCount(3, $tables);
        self::assertContains($backend->toIdentifier("languagesLoaded"), $tables);
        self::assertContains($backend->toIdentifier("numbersLoaded"), $tables);
        self::assertContains($backend->toIdentifier("numbersAliasLoaded"), $tables);

        // check table structure and data
        $data = $backend->fetchAll("languagesLoaded", \PDO::FETCH_ASSOC);
        self::assertCount(2, $data[0], 'there should be two columns');
        self::assertArrayHasKey('id', $data[0]);
        self::assertArrayHasKey('name', $data[0]);
        $this->assertArrayEqualsSorted(
            Client::parseCsv(file_get_contents(__DIR__ . '/../../_data/languages.csv'), true, ",", '"'),
            $data,
            'id'
        );

        // now we'll load another table and use the preserve parameters to check that all tables are present
        // lets create it now to see if the table permissions are correctly propagated
        $table3Id = $this->_client->createTable(
            $bucketId,
            'numbersLater',
            new CsvFile(__DIR__ . '/../../_data/numbers.csv')
        );

        $runId = $this->_client2->generateRunId();
        $this->_client2->setRunId($runId);

        $mapping3 = ["source" => str_replace($bucketId, $linkedId, $table3Id), "destination" => "table3"];
        $workspaces->loadWorkspaceData($workspace['id'], ["input" => [$mapping3], "preserve" => true]);

        $this->createAndWaitForEvent(
            (new \Keboola\StorageApi\Event())->setComponent('dummy')->setMessage('dummy'),
            $this->_client2
        );

        $events = $this->_client2->listEvents(['runId' => $runId, 'q' => 'storage.workspaceLoaded',]);
        self::assertCount(1, $events);
        self::assertSame($expectedLoadType, $events[0]['results']['loadType']);

        $tables = $backend->getTables();

        self::assertCount(4, $tables);
        self::assertContains($backend->toIdentifier("table3"), $tables);
        self::assertContains($backend->toIdentifier("languagesLoaded"), $tables);
        self::assertContains($backend->toIdentifier("numbersLoaded"), $tables);
        self::assertContains($backend->toIdentifier("numbersAliasLoaded"), $tables);

        // now we'll try the same load, but it should clear the workspace first (preserve is false by default)
        $runId = $this->_client2->generateRunId();
        $this->_client2->setRunId($runId);

        $workspaces->loadWorkspaceData($workspace['id'], ["input" => [$mapping3]]);

        $this->createAndWaitForEvent(
            (new \Keboola\StorageApi\Event())->setComponent('dummy')->setMessage('dummy'),
            $this->_client2
        );

        $events = $this->_client2->listEvents(['runId' => $runId, 'q' => 'storage.workspaceLoaded',]);
        self::assertCount(1, $events);
        self::assertSame($expectedLoadType, $events[0]['results']['loadType']);

        $tables = $backend->getTables();
        self::assertCount(1, $tables);
        self::assertContains($backend->toIdentifier("table3"), $tables);

        // now try load as view
        if ($this->isExasolTestCase($sharingBackend, $workspaceBackend)) {
            $inputAsView = [
                [
                    "source" => str_replace($bucketId, $linkedId, $table1Id),
                    "destination" => "languagesLoaded",
                    "useView" => true,
                ],

                [
                    "source" => str_replace($bucketId, $linkedId, $table2Id),
                    "destination" => "numbersLoaded",
                    "useView" => true,
                ],

                [
                    "source" => str_replace($bucketId, $linkedId, $table3Id),
                    "destination" => "numbersAliasLoaded",
                    "useView" => true,
                ],
            ];
            try {
                $workspaces->loadWorkspaceData($workspace['id'], ["input" => $inputAsView]);
                self::fail('View load with linked tables must fail.');
            } catch (ClientException $e) {
                self::assertSame('View load is not supported, table "languages" is alias or linked.', $e->getMessage());
                self::assertEquals('workspace.loadRequestLogicalException', $e->getStringCode());
            }
            //// check that the tables are in the workspace
            //$views = ($backend->getSchemaReflection())->getViewsNames();
            //self::assertCount(3, $views);
            //self::assertCount(0, $backend->getTables());
            //self::assertContains($backend->toIdentifier("languagesLoaded"), $views);
            //self::assertContains($backend->toIdentifier("numbersLoaded"), $views);
            //self::assertContains($backend->toIdentifier("numbersAliasLoaded"), $views);
            //
            //// check table structure and data
            //$data = $backend->fetchAll("languagesLoaded", \PDO::FETCH_ASSOC);
            //self::assertCount(5, $data, 'there should be 5 rows');
            //self::assertCount(3, $data[0], 'there should be two columns');
            //self::assertArrayHasKey('id', $data[0]);
            //self::assertArrayHasKey('name', $data[0]);
            //self::assertArrayHasKey('_timestamp', $data[0]);
        }

        // unload validation
        $connection = $workspace['connection'];

        $backend = null; // force disconnect of same SNFLK connection
        $db = $this->getDbConnection($connection);

        if ($db instanceof \Doctrine\DBAL\Connection) {
            $db->query("CREATE TABLE [Languages3] (
			[Id] INTEGER NOT NULL,
			[NAME] VARCHAR(10) NOT NULL
		);");
            $db->query("INSERT INTO [Languages3] ([Id], [NAME]) VALUES (1, 'cz');");
            $db->query("INSERT INTO [Languages3] ([Id], [NAME]) VALUES (2, 'en');");
        } else {
            $db->query("CREATE TABLE \"test.Languages3\" (
			\"Id\" integer NOT NULL,
			\"Name\" varchar NOT NULL
		);");
            $db->query("INSERT INTO \"test.Languages3\" (\"Id\", \"Name\") VALUES (1, 'cz'), (2, 'en');");
        }
        try {
            $this->_client2->createTableAsyncDirect($linkedId, [
                'name' => 'languages3',
                'dataWorkspaceId' => $workspace['id'],
                'dataTableName' => 'Languages3',
            ]);

            self::fail('Unload to liked bucket should fail with access exception');
        } catch (ClientException $e) {
            self::assertEquals('accessDenied', $e->getStringCode());
        }
    }

    /**
     * @param string $sharingBackend
     * @param string $workspaceBackend
     * @return bool
     */
    private function isExasolTestCase(
        $sharingBackend,
        $workspaceBackend
    ) {
        return $sharingBackend === self::BACKEND_EXASOL && $workspaceBackend === self::BACKEND_EXASOL;
    }

    /**
     * @param string $tableId
     * @param string $columnName
     */
    private function assertExpectedDistributionKeyColumn($tableId, $columnName)
    {
        $table = $this->_client->getTable($tableId);
        self::assertSame([$columnName], $table['distributionKey']);
    }
}
