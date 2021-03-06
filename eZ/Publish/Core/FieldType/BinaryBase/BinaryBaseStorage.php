<?php
/**
 * File containing the BinaryBaseStorage class
 *
 * @copyright Copyright (C) 1999-2013 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\Core\FieldType\BinaryBase;

use eZ\Publish\Core\FieldType\GatewayBasedStorage;
use eZ\Publish\Core\IO\IOService;
use eZ\Publish\SPI\FieldType\BinaryBase\PathGenerator;
use eZ\Publish\SPI\Persistence\Content\VersionInfo;
use eZ\Publish\SPI\Persistence\Content\Field;
use eZ\Publish\SPI\IO\MimeTypeDetector;

/**
 * Storage for binary files
 */
class BinaryBaseStorage extends GatewayBasedStorage
{
    /**
     * An instance of IOService configured to store to the images folder
     *
     * @var IOService
     */
    protected $IOService;

    /** @var PathGenerator */
    protected $pathGenerator;

    /**
     * @var MimeTypeDetector
     */
    protected $mimeTypeDetector;

    /**
     * Construct from gateways
     *
     * @param \eZ\Publish\Core\FieldType\StorageGateway[] $gateways
     * @param IOService $IOService
     * @param PathGenerator $pathGenerator
     */
    public function __construct( array $gateways, IOService $IOService, PathGenerator $pathGenerator, MimeTypeDetector $mimeTypeDetector )
    {
        parent::__construct( $gateways );
        $this->IOService = $IOService;
        $this->pathGenerator = $pathGenerator;
        $this->mimeTypeDetector = $mimeTypeDetector;
    }

    /**
     * Allows custom field types to store data in an external source (e.g. another DB table).
     *
     * Stores value for $field in an external data source.
     * The whole {@link eZ\Publish\SPI\Persistence\Content\Field} ValueObject is passed and its value
     * is accessible through the {@link eZ\Publish\SPI\Persistence\Content\FieldValue} 'value' property.
     * This value holds the data filled by the user as a {@link eZ\Publish\Core\FieldType\Value} based object,
     * according to the field type (e.g. for TextLine, it will be a {@link eZ\Publish\Core\FieldType\TextLine\Value} object).
     *
     * $field->id = unique ID from the attribute tables (needs to be generated by
     * database back end on create, before the external data source may be
     * called from storing).
     *
     * The context array provides some context for the field handler about the
     * currently used storage engine.
     * The array should at least define 2 keys :
     *   - identifier (connection identifier)
     *   - connection (the connection handler)
     * For example, using Legacy storage engine, $context will be:
     *   - identifier = 'LegacyStorage'
     *   - connection = {@link \eZ\Publish\Core\Persistence\Legacy\EzcDbHandler} object handler (for DB connection),
     *                  to be used accordingly to
     *                  {@link http://incubator.apache.org/zetacomponents/documentation/trunk/Database/tutorial.html ezcDatabase} usage
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param array $context
     *
     * @return void
     */
    public function storeFieldData( VersionInfo $versionInfo, Field $field, array $context )
    {
        if ( $field->value->externalData === null )
        {
            // Nothing to store
            return false;
        }

        // no mimeType means we are dealing with an input, local file
        if ( !isset( $field->value->externalData['mimeType'] ) )
        {
            $field->value->externalData['mimeType'] = $this->mimeTypeDetector->getFromPath( $field->value->externalData['id'] );
        }

        $storedValue = $field->value->externalData;
        $storagePath = $this->pathGenerator->getStoragePathForField( $field, $versionInfo );

        // The file referenced in externalData MAY be an existing IOService file which we can use
        if ( ( $this->IOService->loadBinaryFile( $storedValue['id'] ) === false ) &&
             ( $this->IOService->loadBinaryFile( $storagePath ) === false ) )
        {
            $createStruct = $this->IOService->newBinaryCreateStructFromLocalFile(
                $storedValue['id']
            );
            $createStruct->id = $storagePath;
            $binaryFile = $this->IOService->createBinaryFile( $createStruct );
            $storedValue['id'] = $binaryFile->id;
            $storedValue['mimeType'] = $createStruct->mimeType;
            $storedValue['uri'] = $binaryFile->uri;
        }

        $field->value->externalData = $storedValue;

        $this->removeOldFile( $field->id, $versionInfo->versionNo, $context );

        return $this->getGateway( $context )->storeFileReference( $versionInfo, $field );
    }

    public function copyLegacyField( VersionInfo $versionInfo, Field $field, Field $originalField, array $context )
    {
        if ( $originalField->value->data === null )
            return false;

        // field translations have their own file reference, but to the original file
        $originalField->value->externalData['id'];

        return $this->getGateway( $context )->storeFileReference( $versionInfo, $field );
    }

    /**
     * Removes the old file referenced by $fieldId in $versionNo, if not
     * referenced else where
     *
     * @param mixed $fieldId
     * @param string $versionNo
     * @param array $context
     *
     * @return void
     */
    protected function removeOldFile( $fieldId, $versionNo, array $context )
    {
        $gateway = $this->getGateway( $context );

        $fileReference = $gateway->getFileReferenceData( $fieldId, $versionNo );
        if ( $fileReference === null )
        {
            // No previous file
            return;
        }

        $gateway->removeFileReference( $fieldId, $versionNo );

        $fileCounts = $gateway->countFileReferences( array( $fileReference['id'] ) );

        if ( $fileCounts[$fileReference['id']] === 0 )
        {
            $this->IOService->deleteBinaryFile(
                $this->IOService->loadBinaryFile( $fileReference['id'] )
            );
        }
    }

    /**
     * Populates $field value property based on the external data.
     * $field->value is a {@link eZ\Publish\SPI\Persistence\Content\FieldValue} object.
     * This value holds the data as a {@link eZ\Publish\Core\FieldType\Value} based object,
     * according to the field type (e.g. for TextLine, it will be a {@link eZ\Publish\Core\FieldType\TextLine\Value} object).
     *
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param array $context
     *
     * @return void
     */
    public function getFieldData( VersionInfo $versionInfo, Field $field, array $context )
    {
        $field->value->externalData = $this->getGateway( $context )->getFileReferenceData( $field->id, $versionInfo->versionNo );
        if ( $field->value->externalData !== null )
        {
            if ( ( $binaryFile = $this->IOService->loadBinaryFile( $field->value->externalData['id'] ) ) !== false )
            {
                $field->value->externalData['fileSize'] = $binaryFile->size;
                $field->value->externalData['uri'] = $binaryFile->uri;
            }
            else
            {
                throw new \RuntimeException( "Failed loading binary file {$field->value->externalData['id']}" );
            }
        }
    }

    /**
     * Deletes all referenced external data
     *
     * @param VersionInfo $versionInfo
     * @param array $fieldIds
     * @param array $context
     *
     * @return boolean
     */
    public function deleteFieldData( VersionInfo $versionInfo, array $fieldIds, array $context )
    {
        if ( empty( $fieldIds ) )
        {
            return;
        }

        $gateway = $this->getGateway( $context );

        $referencedFiles = $gateway->getReferencedFiles( $fieldIds, $versionInfo->versionNo );

        $gateway->removeFileReferences( $fieldIds, $versionInfo->versionNo );

        $referenceCountMap = $gateway->countFileReferences( $referencedFiles );

        foreach ( $referenceCountMap as $filePath => $count )
        {
            if ( $count === 0 )
            {
                $this->IOService->deleteBinaryFile(
                    $this->IOService->loadBinaryFile( $filePath )
                );
            }
        }
    }

    /**
     * Checks if field type has external data to deal with
     *
     * @return boolean
     */
    public function hasFieldData()
    {
        return true;
    }

    /**
     * @param \eZ\Publish\SPI\Persistence\Content\VersionInfo $versionInfo
     * @param \eZ\Publish\SPI\Persistence\Content\Field $field
     * @param array $context
     *
     * @return void
     */
    public function getIndexData( VersionInfo $versionInfo, Field $field, array $context )
    {

    }
}
