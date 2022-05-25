<?php

namespace Keboola\Test\Backend\Workspaces;

use Keboola\StorageApi\ClientException;
use Keboola\Test\Backend\WorkspaceConnectionTrait;
use Keboola\Test\Backend\Workspaces\Backend\WorkspaceBackendFactory;

class SynapseWorkspacesUnloadTest extends ParallelWorkspacesTestCase
{
    use WorkspaceConnectionTrait;

    public function testCreateTableFromWorkspace(): void
    {
        // create workspace and source table in workspace
        $workspace = $this->initTestWorkspace();

        $tableId = 'Languages3';

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);
        $backend->dropTableIfExists($tableId);

        $connection = $workspace['connection'];

        $db = $this->getDbConnectionSynapse($connection);

        $quotedTableId = $db->getDatabasePlatform()->quoteIdentifier(sprintf(
            '%s.%s',
            $connection['schema'],
            $tableId
        ));

        $db->executeQuery("create table $quotedTableId (
			[Id] integer not null,
			[Name] varchar(50) not null
		);");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name]) values (1, 'cz');");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name]) values (2, 'en');");

        // create table from workspace
        $tableId = $this->_client->createTableAsyncDirect($this->getTestBucketId(self::STAGE_IN), [
            'name' => 'languages3',
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => $tableId,
        ]);

        $expected = [
            '"Id","Name"',
            '"1","cz"',
            '"2","en"',
        ];

        $this->assertLinesEqualsSorted(implode("\n", $expected) . "\n", $this->_client->getTableDataPreview($tableId, [
            'format' => 'rfc',
        ]), 'imported data comparsion');
    }

    public function testCreateTableFromWorkspaceWithInvalidColumnNames(): void
    {
        // create workspace and source table in workspace
        $workspace = $this->initTestWorkspace();

        $tableId = 'Languages3';

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);
        $backend->dropTableIfExists($tableId);

        $connection = $workspace['connection'];

        $db = $this->getDbConnectionSynapse($connection);

        $quotedTableId = $db->getDatabasePlatform()->quoteIdentifier(sprintf(
            '%s.%s',
            $connection['schema'],
            $tableId
        ));

        $db->executeQuery("create table $quotedTableId (
			[_Id] integer not null,
			[Name] varchar(50) not null
		);");
        $db->executeQuery("insert into $quotedTableId ([_Id], [Name]) values (1, 'cz');");
        $db->executeQuery("insert into $quotedTableId ([_Id], [Name]) values (2, 'en');");

        try {
            $this->_client->createTableAsyncDirect($this->getTestBucketId(self::STAGE_IN), [
                'name' => 'languages3',
                'dataWorkspaceId' => $workspace['id'],
                'dataTableName' => $tableId,
            ]);
            $this->fail('Table should not be created');
        } catch (ClientException $e) {
            $this->assertEquals('storage.invalidColumns', $e->getStringCode());
            $this->assertStringContainsString('_Id', $e->getMessage());
        }
    }

    public function testImportFromWorkspaceWithInvalidColumnNames(): void
    {
        // create workspace and source table in workspace
        $workspace = $this->initTestWorkspace();

        $tableId = 'Languages3';

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);
        $backend->dropTableIfExists($tableId);

        $connection = $workspace['connection'];

        $db = $this->getDbConnectionSynapse($connection);

        $quotedTableId = $db->getDatabasePlatform()->quoteIdentifier(sprintf(
            '%s.%s',
            $connection['schema'],
            $tableId
        ));

        $db->executeQuery("create table $quotedTableId (
			[Id] integer not null,
			[Name] varchar(50) not null,
			[_update] varchar(50) not null
		);");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name], [_update]) values (1, 'cz', 'x');");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name], [_update]) values (2, 'en', 'z');");

        $table = $this->_client->apiPost("buckets/" . $this->getTestBucketId(self::STAGE_IN) . "/tables", [
            'dataString' => 'Id,Name',
            'name' => 'languages',
            'primaryKey' => 'Id',
        ]);

        try {
            $this->_client->writeTableAsyncDirect($table['id'], [
                'dataWorkspaceId' => $workspace['id'],
                'dataTableName' => $tableId,
                'incremental' => true,
            ]);
            $this->fail('Table should not be imported');
        } catch (ClientException $e) {
            $this->assertEquals('storage.invalidColumns', $e->getStringCode());
            $this->assertStringContainsString('_update', $e->getMessage());
        }
    }

    public function testCopyImport(): void
    {
        $table = $this->_client->apiPost("buckets/" . $this->getTestBucketId(self::STAGE_IN) . "/tables", [
            'dataString' => 'Id,Name,update',
            'name' => 'languages',
            'primaryKey' => 'Id',
        ]);

        // create workspace and source table in workspace
        $workspace = $this->initTestWorkspace();

        $tableId = 'Languages3';

        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);
        $backend->dropTableIfExists($tableId);

        $connection = $workspace['connection'];

        $db = $this->getDbConnectionSynapse($connection);

        $quotedTableId = $db->getDatabasePlatform()->quoteIdentifier(sprintf(
            '%s.%s',
            $connection['schema'],
            $tableId
        ));

        $db->executeQuery("create table $quotedTableId (
			[Id] integer not null,
			[Name] varchar(50) not null,
			[update] varchar(50)
		);");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name]) values (1, 'cz');");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name]) values (2, 'en');");

        $this->_client->writeTableAsyncDirect($table['id'], [
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => $tableId,
        ]);

        $expected = [
            '"Id","Name","update"',
            '"1","cz",""',
            '"2","en",""',
        ];

        $this->assertLinesEqualsSorted(implode("\n", $expected) . "\n", $this->_client->getTableDataPreview($table['id'], [
            'format' => 'rfc',
        ]), 'imported data comparsion');

        $db->executeQuery("truncate table $quotedTableId");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name], [update]) values (1, 'cz', '1');");
        $db->executeQuery("insert into $quotedTableId ([Id], [Name], [update]) values (3, 'sk', '1');");

        $this->_client->writeTableAsyncDirect($table['id'], [
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => $tableId,
            'incremental' => true,
        ]);

        $expected = [
            '"Id","Name","update"',
            '"1","cz","1"',
            '"2","en",""',
            '"3","sk","1"',
        ];
        $this->assertLinesEqualsSorted(implode("\n", $expected) . "\n", $this->_client->getTableDataPreview($table['id'], [
            'format' => 'rfc',
        ]), 'previously null column updated');

        $db->executeQuery("truncate table $quotedTableId");
        $db->executeQuery("alter table $quotedTableId add [new_col] varchar(50)");

        $db->executeQuery("insert into $quotedTableId values (1, 'cz', '1', null);");
        $db->executeQuery("insert into $quotedTableId values (3, 'sk', '1', 'newValue');");

        $this->_client->writeTableAsyncDirect($table['id'], [
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => $tableId,
            'incremental' => true,
        ]);

        $expected = [
            '"Id","Name","update","new_col"',
            '"1","cz","1",""',
            '"2","en","",""',
            '"3","sk","1","newValue"',
        ];
        $this->assertLinesEqualsSorted(implode("\n", $expected) . "\n", $this->_client->getTableDataPreview($table['id'], [
            'format' => 'rfc',
        ]), 'new  column added');
    }
}
