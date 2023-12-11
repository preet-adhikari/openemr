<?php

/**
 * Represents a very basic in memory data store used for illustrating the FHIR API
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2022 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\FHIRDeviceRequest;

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\BaseService;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\Services\Search\FhirSearchWhereClauseBuilder;
use OpenEMR\Common\Database\QueryUtils;

class FHIRDeviceRequestDataStore extends BaseService
{

    private const DEVICEREQUEST_TABLE = 'device_request';

    /**
     * Default constructor.
     */
    public function __construct()
    {
        parent::__construct(self::DEVICEREQUEST_TABLE);
        UuidRegistry::createMissingUuidsForTables([self::DEVICEREQUEST_TABLE]);
    }
    

    /**
     * Returns a custom skeleton record for the given id, or null if none found
     * @param $id
     * @return array|null
     */
    public function getById($id)
    {
        return $this->getByField('_id', $id)[0] ?? null;
    }

    /**
     * Returns all of the custom skeleton records that match the given patientId passed in.
     * @param $patientId
     * @return array
     */
    public function getByPatient($patientId)
    {
        return $this->getByField('_patient', $patientId);
    }

    protected function getByField($field, $value)
    {
        $dataStore = $this->getResourceDataStore();
        $result = [];
        foreach ($dataStore as $record)
        {
            if ($record[$field] == $value)
            {
                $result[] = $record;
            }
        }
        return $result;
    }

    /**
     * Returns the entire data store of custom skeleton records.
     * @return ProcessingResult
     */
    public function getResourceDataStore($search = array(), $isAndCondition = true, $puuidBind = null)
    {
        $resources = [
            ['_id' => 1 ,'_message' => 'This is resource 1', '_patient' => 1]
            // ,['_id' => 2 ,'_message' => 'This is resource 2', '_patient' => 2]
            // ,['_id' => 3, '_message' => 'This is resource 3', '_patient' => 2]
        ];

        //Working on SQL script
        // $sql = "SELECT 
        //         device_request.uuid,
        //         device_request.pid,
        //         device_request.encounter,
        //         device_request.date,
        //         device_request.pruuid,
        //         device_request.location_uuid,
        //         device_request.device_uuid,
        //         device_request.organization_uuid,
        //         device_request.status,
        //         device_request.intent,
        //         device_request.priority,
        //         device_request.insurance_uuid,
        //         device_request.supporting_info,
        //         device_request.note,
        //         device_request.relevant_history_uuid,
        //         device_request.last_updated";

        $sql = "SELECT * FROM device_request
            LEFT JOIN device_code ON device_request.device_code_id = device_code.id 
        ";

        $whereClause = FhirSearchWhereClauseBuilder::build($search, $isAndCondition);

        $sql .= $whereClause->getFragment();
        
        $sqlBindArray = $whereClause->getBoundValues();
        $statementResults =  QueryUtils::sqlStatementThrowException($sql, $sqlBindArray);
    
        $processingResult = new ProcessingResult();
        while ($row = sqlFetchArray($statementResults)) {
            $record = $this->createResultRecordFromDatabaseResult($row);
            $processingResult->addData($record);
        }
        return $processingResult;
    }

    public function search($search, $isAndCondition = true)
    {
        
        return new ProcessingResult();
    }


    // UUID fields to convert into string
    public function getUuidFields(): array
    {
        return ['uuid', 'pid', 'encounter', 'pruuid', 'location_uuid', 'device_uuid', 'organization_uuid', 'insurance_uuid', 'relevant_history_uuid'];
    }

    protected function createResultRecordFromDatabaseResult($row)
    {
        $record = parent::createResultRecordFromDatabaseResult($row);
        // var_dump($record);
        return $record;
        
    }
}
