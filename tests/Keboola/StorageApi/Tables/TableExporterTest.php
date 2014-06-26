<?php
/**
 * Created by JetBrains PhpStorm.
 * User: martinhalamicek
 * Date: 22/05/14
 * Time: 16:38
 * To change this template use File | Settings | File Templates.
 */

use Keboola\StorageApi\Client,
	Keboola\Csv\CsvFile,
	Keboola\StorageApi\TableExporter;

class Keboola_StorageApi_Tables_TableExporterTest extends StorageApiTestCase
{


	private $downloadPath;
	private $downloadPathGZip;

	public function setUp()
	{
		parent::setUp();
		$this->_initEmptyBucketsForAllBackends();
		$this->downloadPath = __DIR__ . '/../_tmp/languages.sliced.csv';
		if (file_exists($this->downloadPath)) {
			unlink($this->downloadPath);
		}
		$this->downloadPathGZip = __DIR__ . '/../_tmp/languages.sliced.csv.gz';
		if (file_exists($this->downloadPathGZip)) {
			unlink($this->downloadPathGZip);
		}


	}

	/**
	 * @dataProvider tableImportData
	 * @param $importFileName
	 */
	public function testTableAsyncExport($backend, CsvFile $importFile, $expectationsFileName, $exportOptions=array())
	{
		$expectationsFile = __DIR__ . '/../_data/' . $expectationsFileName;

		if (!isset($exportOptions['format'])) {
			$exportOptions['format'] = 'rfc';
		}
		if (!isset($exportOptions['gzip']) ) {
			$exportOptions['gzip'] = false;
		}


		$tableId = $this->_client->createTable($this->getTestBucketId(self::STAGE_IN, $backend), 'languages', $importFile);
		$result = $this->_client->writeTable($tableId, $importFile);

		$this->assertEmpty($result['warnings']);
		$exporter = new TableExporter($this->_client);

		if ($exportOptions['gzip'] === true) {
			$exporter->exportTable($tableId, $this->downloadPathGZip, $exportOptions);
			(new \Symfony\Component\Process\Process("gunzip " . escapeshellarg($this->downloadPathGZip)))->mustRun();
		} else {
			$exporter->exportTable($tableId, $this->downloadPath, $exportOptions);
		}


		// compare data
		$this->assertTrue(file_exists($this->downloadPath));
		$this->assertLinesEqualsSorted(file_get_contents($expectationsFile), file_get_contents($this->downloadPath), 'imported data comparsion');

	}


	public function tableImportData()
	{
		return array(
			array(self::BACKEND_MYSQL, new CsvFile('https://s3.amazonaws.com/keboola-tests/languages.csv.gz'), 'languages.csv'),
			array(self::BACKEND_MYSQL, new CsvFile('https://s3.amazonaws.com/keboola-tests/languages.csv.gz'), 'languages.csv', array('gzip' => true)),

			array(self::BACKEND_REDSHIFT, new CsvFile('https://s3.amazonaws.com/keboola-tests/escaping.csv'), 'escaping.raw.out.redshift.csv', array('format' => 'raw')),
			array(self::BACKEND_REDSHIFT, new CsvFile('https://s3.amazonaws.com/keboola-tests/escaping.csv'), 'escaping.raw.out.redshift.csv', array('gzip' => true, 'format' => 'raw')),
			array(self::BACKEND_REDSHIFT, new CsvFile('https://s3.amazonaws.com/keboola-tests/escaping.csv'), 'escaping.standard.out.csv', array('gzip' => true)),
		);
	}


}