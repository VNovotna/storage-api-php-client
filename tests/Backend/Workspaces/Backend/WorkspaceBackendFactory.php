<?php
/**
 * Created by PhpStorm.
 * User: marc
 * Date: 27/07/2016
 * Time: 14:03
 */

namespace Keboola\Test\Backend\Workspaces\Backend;

use Keboola\StorageApi\Exception;
use Keboola\Test\StorageApiTestCase;

class WorkspaceBackendFactory
{
    /**
     * @param $workspace
     * @return WorkspaceBackend
     * @throws Exception
     */
    public static function createWorkspaceBackend($workspace)
    {

        switch ($workspace['connection']['backend']) {
            case StorageApiTestCase::BACKEND_REDSHIFT:
                return new RedshiftWorkspaceBackend($workspace);
            case StorageApiTestCase::BACKEND_SNOWFLAKE:
                return new SnowflakeWorkspaceBackend($workspace);
            case StorageApiTestCase::BACKEND_SYNAPSE:
                return new SynapseWorkspaceBackend($workspace);
            case StorageApiTestCase::BACKEND_EXASOL:
                return new ExasolWorkspaceBackend($workspace);
            default:
                throw new Exception($workspace['connection']['backend'] . " workspaces are not supported.");
        }
    }
}
