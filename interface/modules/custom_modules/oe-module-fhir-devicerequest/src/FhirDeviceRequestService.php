<?php

/**
 * FHIR Resource Service class example for implementing the methods that are typically used with FHIR resources via the
 * api.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2022 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\FHIRDeviceRequest;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRProvenance;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRDeviceRequest;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\IPatientCompartmentResourceService;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\NumberSearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDateTime;
use OpenEMR\Services\FHIR\FhirOrganizationService;
use OpenEMR\Services\FHIR\FhirProvenanceService;



class FhirDeviceRequestService extends FhirServiceBase implements IPatientCompartmentResourceService
{
    /**
     * If you'd prefer to keep out the empty methods that are doing nothing uncomment the following helper trait
     */
   use FhirServiceBaseEmptyTrait;

   // Statuses for DeviceRequest Resource 
//    const DEVICE_REQUEST_STATUS_DRAFT = "draft";
//    const DEVICE_REQUEST_STATUS_ACTIVE = "active";
//    const DEVICE_REQUEST_STATUS_ON_HOLD = "on-hold";
//    const DEVICE_REQUEST_STATUS_REVOKED = "revoked";
//    const DEVICE_REQUEST_STATUS_COMPLETED = "completed";
//    const DEVICE_REQUEST_STATUS_ENTERED_IN_ERROR = "entered-in-error";
//    const DEVICE_REQUEST_STATUS_UNKNOWN = "unknown";

    const DEVICE_REQUEST_INTENT_PLAN = "plan";
    const DEVICE_REQUEST_INTENT_ORDER = "order";
    

    /**
     * @var FHIRDeviceRequestDataStore The in memory sample data store we use for data population with our module
     */
    private $dataStore;

    public function __construct()
    {
        parent::__construct();
        $this->dataStore = new FHIRDeviceRequestDataStore();
    }


        /**
     * This method returns the FHIR search definition objects that are used to map FHIR search fields to OpenEMR fields.
     * Since the mapping can be one FHIR search object to many OpenEMR fields, we use the search definition objects.
     * Search fields can be combined as Composite fields and represent a host of search options.
     * @see https://www.hl7.org/fhir/search.html to see the types of search operations, and search types that are available
     * for use.
     * @return array
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'intent' => new FhirSearchParameterDefinition('intent', SearchFieldType::TOKEN, ['intent']),
            'status' => new FhirSearchParameterDefinition('status', SearchFieldType::TOKEN, ['status']),
            '_id' => new FhirSearchParameterDefinition('uuid', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
        ];
    }


    /**
     * @param array $dataRecord
     * @param bool $encode
     * 
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $deviceRequestResource = new FHIRDeviceRequest();
        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        $meta->setLastUpdated(gmdate('c'));
        $deviceRequestResource->setMeta($meta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $deviceRequestResource->setId($id);

        // Set status for the DeviceRequest
        if(isset($dataRecord['status']))
        {
            $deviceRequestResource->setStatus($dataRecord['status']);
        }

        //Required. Set the required intent for the DeviceRequest resource.
        if (isset($dataRecord['intent']))
        {
            $deviceRequestResource->setIntent($dataRecord['intent']);
        } else
        {
            // if we are missing the intent for whatever reason we should convey the code that does the least harm
            // which is that this is a plan but does not have authorization
            $deviceRequestResource->setIntent(self::DEVICE_REQUEST_INTENT_PLAN);
        } 

        // Set the subject of the device request
        // subject required
        if (!empty($dataRecord['pid'])) {
            $subjectType = 'Patient';
            $subjectId = $dataRecord['pid'];
        } elseif (!empty($dataRecord['device_uuid'])) {
            $subjectType = 'Device';
            $subjectId = $dataRecord['device_uuid'];
        } elseif (!empty($dataRecord['location_uuid'])) {
            $subjectType = 'Location';
            $subjectId = $dataRecord['location_uuid'];
        } else {
            $deviceRequestResource->setSubject(UtilsService::createDataMissingExtension());
        }
        
        if (isset($subjectType)) {
            $deviceRequestResource->setSubject(UtilsService::createRelativeReference($subjectType, $subjectId));
        }

        

        // Set the priority for the DeviceRequest resource.
        if (isset($dataRecord['priority'])){
            $deviceRequestResource->setPriority($dataRecord['priority']);
        }

        // authoredOn must support
        if (!empty($dataRecord['event_date'])) {
            $authored_on = new FHIRDateTime();
            $authored_on->setValue(UtilsService::getLocalDateAsUTC($dataRecord['event_date']));
            $deviceRequestResource->setAuthoredOn($authored_on);
        }

         // requester required
         if (!empty($dataRecord['pruuid'])) {
            $deviceRequestResource->setRequester(UtilsService::createRelativeReference('Practitioner', $dataRecord['pruuid']));
        } else {
            // if we have no practitioner we need to default it to the organization
            $fhirOrgService = new FhirOrganizationService();
            $deviceRequestResource->setRequester($fhirOrgService->getPrimaryBusinessEntityReference());
        }

        // $deviceRequestResource->setText($dataRecord['_message']);
        // $deviceRequestResource->setPatient(UtilsService::createRelativeReference("Patient", $dataRecord['_patient']));
        if ($encode) {
            return json_encode($deviceRequestResource);
        } else {
            return $deviceRequestResource;
        }
    }


    /**
     * @return FhirSearchParameterDefinition Returns the search field definition for the patient search field
     */
    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::TOKEN, [new ServiceField('patient', ServiceField::TYPE_STRING)]);
    }

    protected function searchForOpenEMRRecords($openEMRSearchParameters, $puuidBind = null): ProcessingResult
    {
        // $result = new ProcessingResult();

        // if (empty($openEMRSearchParameters)) {
        //     // just return everything
        //     $data = $this->dataStore->getResourceDataStore();
        //     foreach ($data as $record) {
        //         $result->addData($record);
        //     }
        // }

        // if (isset($openEMRSearchParameters['_id']) && $openEMRSearchParameters['_id'] instanceof TokenSearchField) {
        //     /**
        //      * Note that SearchFields can have multiple values.  We are ignoring all modifiers here, but look at the
        //      * core OpenEMR service classes to see how the modifiers are used in SQL
        //      */
        //     $searchValues = $openEMRSearchParameters['_id']->getValues() ?? [];
        //     foreach ($searchValues as $tokenValue) {
        //         /**
        //          * Token search fields have both a code and a system that can be searched on.  For our simple search
        //          * we will just grab the code.  More complicated examples can be seen in the core classes.
        //          */
        //         $value = $tokenValue->getCode();
        //         $record = $this->dataStore->getById($value);
        //         if (!empty($record)) {
        //             $result->addData($record);
        //         }
        //     }
        // }
        // $patientFieldName = $this->getPatientContextSearchField()->getName();
        // if (
        //     isset($openEMRSearchParameters[$patientFieldName])
        //     && $openEMRSearchParameters[$patientFieldName] instanceof TokenSearchField
        // ) {
        //     $searchValues = $openEMRSearchParameters[$patientFieldName]->getValues() ?? [];
        //     foreach ($searchValues as $tokenValue) {
        //         /**
        //          * Token search fields have both a code and a system that can be searched on.  For our simple search
        //          * we will just grab the code.  More complicated examples can be seen in the core classes.
        //          */
        //         $value = $tokenValue->getCode();
        //         $records = $this->dataStore->getByPatient($value);
        //         if (!empty($records)) {
        //             foreach ($records as $record) {
        //                 $result->addData($record);
        //             }
        //         }
        //     }
        // }
        return $this->dataStore->getResourceDataStore($openEMRSearchParameters, true, $puuidBind);
    }

    /**
     * Healthcare resources often need to provide an AUDIT trail of who last touched a resource and when was it modified.
     * The ownership and AUDIT trail in FHIR is done via the Provenance record.
     * @param FHIRDomainResource $dataRecord The record we are generating a provenance from
     * @param bool $encode Whether to serialize the record or not
     * @return FHIRProvenance
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        // we don't return any provenance authorship for this custom resource
        // if we did return it, we would fill out the following record
//        $provenance = new FHIRProvenance();
        if  (!($dataRecord instanceof FHIRDeviceRequest)) 
        {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord);
        if ($encode)
        {
            return json_encode($fhirProvenance);
        } 
        else
        {
            return $fhirProvenance;
        }
    }

    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        throw new \BadMethodCallException("This method is not implemented at this point in time");
    }


    /**
     * If our REST endpoint implemented the PUT/POST method at the resource endpoint we would implement this insert
     * The method receives the result of the parseFhirResource method.
     * @param $openEmrRecord
     * @return void
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        throw new \BadMethodCallException("This method is not implemented at this point in time");
    }

    /**
     * If our REST endpoint implemented the PATCH/POST method at the resource id endpoint we would implement this update
     * The method receives the result of the parseFhirResource method.
     * @param $fhirResourceId The fhir unique id representing this resource
     * @param $openEmrRecord The parsed open emr record with the values used inside OpenEMR we want to update.
     * @return void
     */
    protected function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        throw new \BadMethodCallException("This method is not implemented at this point in time");
    }
}
