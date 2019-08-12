<?php


namespace Keboola\Test\Backend\Workspaces;

use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Workspaces;
use Keboola\Test\Backend\Workspaces\WorkspacesTestCase;

class MetadataFromSnowflakeWorkspaceTest extends WorkspacesTestCase
{
    public function testCreateTableFromWorkspace()
    {

        // create workspace and source table in workspace
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace(["backend" => "snowflake"]);
        $connection = $workspace['connection'];
        $db = $this->getDbConnection($connection);
        $db->query("create table \"test.metadata_columns\" (
                    \"string\" varchar(16) not null default 'string',
                    \"char\" char null,
                    \"integer\" integer not null default 4,
                    \"decimal\" decimal(10,3) not null default 234.123,
                    \"real\" real null,
                    \"double\" double precision null,
                    \"boolean\" boolean not null default true,
                    \"variant\" variant,
                    \"time\" time not null default current_time,
                    \"date\" date not null default current_date,
                    \"timestamp\" timestamp not null default current_timestamp,
                    \"timestampltz\" timestampltz not null default current_timestamp 
                );");
        // create table from workspace
        $tableId = $this->_client->createTableAsyncDirect($this->getTestBucketId(self::STAGE_IN), array(
            'name' => 'metadata_columns',
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => 'test.metadata_columns',
        ));
        $expectedStringMetadata = [
            'KBC.datatype.type' => 'TEXT',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'STRING',
            'KBC.datatype.length' => '16',
            'KBC.datatype.default' => '\'string\'',
        ];
        $expectedCharMetadata = [
            'KBC.datatype.type' => 'TEXT',
            'KBC.datatype.nullable' => '1',
            'KBC.datatype.basetype' => 'STRING',
            'KBC.datatype.length' => '1',
            'KBC.datatype.default' => '',
        ];
        $expectedIntegerMetadata = [
            'KBC.datatype.type' => 'NUMBER',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'NUMERIC',
            'KBC.datatype.length' => '38,0',
            'KBC.datatype.default' => '4',
        ];
        $expectedDecimalMetadata = [
            'KBC.datatype.type' => 'NUMBER',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'NUMERIC',
            'KBC.datatype.length' => '10,3',
            'KBC.datatype.default' => '234.123',
        ];
        $expectedRealMetadata = [
            'KBC.datatype.type' => 'REAL',
            'KBC.datatype.nullable' => '1',
            'KBC.datatype.basetype' => 'FLOAT',
            'KBC.datatype.default' => '',
        ];
        $expectedDoubleMetadata = [
            'KBC.datatype.type' => 'REAL',
            'KBC.datatype.nullable' => '1',
            'KBC.datatype.basetype' => 'FLOAT',
            'KBC.datatype.default' => '',
        ];
        $expectedBooleanMetadata = [
            'KBC.datatype.type' => 'BOOLEAN',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'BOOLEAN',
            'KBC.datatype.default' => 'TRUE',
        ];
        $expectedVariantMetadata = [
            'KBC.datatype.type' => 'VARIANT',
            'KBC.datatype.nullable' => '1',
            'KBC.datatype.basetype' => 'STRING',
            'KBC.datatype.default' => '',
        ];
        $expectedTimeMetadata = [
            'KBC.datatype.type' => 'TIME',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'STRING',
            'KBC.datatype.default' => 'CURRENT_TIME()',
        ];
        $expectedDateMetadata = [
            'KBC.datatype.type' => 'DATE',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'DATE',
            'KBC.datatype.default' => 'CURRENT_DATE()',
        ];
        $expectedTimestampMetadata = [
            'KBC.datatype.type' => 'TIMESTAMP_NTZ',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'TIMESTAMP',
            'KBC.datatype.default' => 'CURRENT_TIMESTAMP()',
        ];
        $expectedTimestamptzMetadata = [
            'KBC.datatype.type' => 'TIMESTAMP_LTZ',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'TIMESTAMP',
            'KBC.datatype.default' => 'CURRENT_TIMESTAMP()',
        ];
        // check that the new table has the correct metadata
        $table = $this->_client->getTable($tableId);

        $this->assertEquals([], $table['metadata']);
        $this->assertArrayHasKey('string', $table['columnMetadata']);
        $this->assertMetadata($expectedStringMetadata, $table['columnMetadata']['string']);
        $this->assertArrayHasKey('char', $table['columnMetadata']);
        $this->assertMetadata($expectedCharMetadata, $table['columnMetadata']['char']);
        $this->assertArrayHasKey('integer', $table['columnMetadata']);
        $this->assertMetadata($expectedIntegerMetadata, $table['columnMetadata']['integer']);
        $this->assertArrayHasKey('decimal', $table['columnMetadata']);
        $this->assertMetadata($expectedDecimalMetadata, $table['columnMetadata']['decimal']);
        $this->assertArrayHasKey('real', $table['columnMetadata']);
        $this->assertMetadata($expectedRealMetadata, $table['columnMetadata']['real']);
        $this->assertArrayHasKey('double', $table['columnMetadata']);
        $this->assertMetadata($expectedDoubleMetadata, $table['columnMetadata']['double']);
        $this->assertArrayHasKey('boolean', $table['columnMetadata']);
        $this->assertMetadata($expectedBooleanMetadata, $table['columnMetadata']['boolean']);
        $this->assertArrayHasKey('variant', $table['columnMetadata']);
        $this->assertMetadata($expectedVariantMetadata, $table['columnMetadata']['variant']);
        $this->assertArrayHasKey('time', $table['columnMetadata']);
        $this->assertMetadata($expectedTimeMetadata, $table['columnMetadata']['time']);
        $this->assertArrayHasKey('date', $table['columnMetadata']);
        $this->assertMetadata($expectedDateMetadata, $table['columnMetadata']['date']);
        $this->assertArrayHasKey('timestamp', $table['columnMetadata']);
        $this->assertMetadata($expectedTimestampMetadata, $table['columnMetadata']['timestamp']);
        $this->assertArrayHasKey('timestampltz', $table['columnMetadata']);
        $this->assertMetadata($expectedTimestamptzMetadata, $table['columnMetadata']['timestampltz']);
    }

    public function testCopyImport()
    {
        $table_id = $this->_client->createTable(
            $this->getTestBucketId(self::STAGE_IN),
            'languages3',
            new CsvFile(__DIR__ . '/../../_data/languages.csv'),
            array('primaryKey' => 'id')
        );

        // create workspace and source table in workspace
        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace(["backend" => "snowflake"]);
        $connection = $workspace['connection'];
        $db = $this->getDbConnection($connection);
        $db->query("create table \"test.Languages3\" (
                \"id\" integer not null,
                \"name\" varchar not null default 'honza'
            );");
        $db->query("insert into \"test.Languages3\" (\"id\", \"name\") values (1, 'cz'), (2, 'en');");
        $this->_client->writeTableAsyncDirect($table_id, array(
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => 'test.Languages3',
        ));
        $expected = array(
            '"id","name"',
            '"1","cz"',
            '"2","en"',
        );
        $this->assertLinesEqualsSorted(
            implode("\n", $expected) . "\n",
            $this->_client->getTableDataPreview($table_id, array('format' => 'rfc')),
            'imported data comparsion'
        );
        // check the created metadata
        $expectedIdMetadata = [
            'KBC.datatype.type' => 'NUMBER',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'NUMERIC',
            'KBC.datatype.length' => '38,0',
            'KBC.datatype.default' => '',
        ];
        $expectedNameMetadata = [
            'KBC.datatype.type' => 'TEXT',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'STRING',
            'KBC.datatype.length' => '16777216',
            'KBC.datatype.default' => '\'honza\'',
        ];

        // check that the new table has the correct metadata
        $table = $this->_client->getTable($table_id);
        $this->assertEquals([], $table['metadata']);
        $this->assertArrayHasKey('id', $table['columnMetadata']);
        $this->assertMetadata($expectedIdMetadata, $table['columnMetadata']['id']);
        $this->assertArrayHasKey('name', $table['columnMetadata']);
        $this->assertMetadata($expectedNameMetadata, $table['columnMetadata']['name']);

        $db->query("truncate table \"test.Languages3\"");
        $db->query("alter table \"test.Languages3\" ADD COLUMN \"update\" varchar(64) NOT NULL DEFAULT '';");
        $db->query("insert into \"test.Languages3\" values " .
            "(1, 'cz', '')," .
            " (3, 'sk', 'newValue')," .
            " (4, 'jp', 'test');");
        $this->_client->writeTableAsyncDirect($table['id'], array(
            'dataWorkspaceId' => $workspace['id'],
            'dataTableName' => 'test.Languages3',
            'incremental' => true,
        ));
        $expected = array(
            '"id","name","update"',
            '"1","cz",""',
            '"2","en",""',
            '"3","sk","newValue"',
            '"4","jp","test"',
        );

        $this->assertLinesEqualsSorted(
            implode("\n", $expected) . "\n",
            $this->_client->getTableDataPreview($table['id'], array('format' => 'rfc')),
            'new  column added'
        );
        $expectedUpdateMetadata = [
            'KBC.datatype.type' => 'TEXT',
            'KBC.datatype.nullable' => '',
            'KBC.datatype.basetype' => 'STRING',
            'KBC.datatype.length' => '64',
            'KBC.datatype.default' => '\'\'',
        ];
        $table = $this->_client->getTable($table['id']);
        $this->assertEquals([], $table['metadata']);
        $this->assertArrayHasKey("id", $table['columnMetadata']);
        $this->assertArrayHasKey("name", $table['columnMetadata']);
        $this->assertArrayHasKey("update", $table['columnMetadata']);
        $this->assertMetadata($expectedUpdateMetadata, $table['columnMetadata']['update']);
    }

    private function assertMetadata($expectedKeyValues, $metadata)
    {
        $this->assertEquals(count($expectedKeyValues), count($metadata));
        foreach ($metadata as $data) {
            $this->assertArrayHasKey("key", $data);
            $this->assertArrayHasKey("value", $data);
            $this->assertEquals($expectedKeyValues[$data['key']], $data['value']);
            $this->assertArrayHasKey("provider", $data);
            $this->assertArrayHasKey("timestamp", $data);
            $this->assertRegExp(self::ISO8601_REGEXP, $data['timestamp']);
            $this->assertEquals('storage', $data['provider']);
        }
    }
}
