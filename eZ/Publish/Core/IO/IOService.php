<?php
/**
 * File containing the eZ\Publish\Core\Repository\IOService class.
 *
 * @copyright Copyright (C) 1999-2013 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\Core\IO;

use eZ\Publish\SPI\IO\Handler;
use eZ\Publish\Core\IO\Values\BinaryFile;
use eZ\Publish\Core\IO\Values\BinaryFileCreateStruct;
use eZ\Publish\SPI\IO\BinaryFile as SPIBinaryFile;
use eZ\Publish\SPI\IO\BinaryFileCreateStruct as SPIBinaryFileCreateStruct;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\Core\Base\Exceptions\NotFoundException;

/**
 * The io service for managing binary files
 *
 * @package eZ\Publish\Core\Repository
 */
class IOService
{
    /**
     * @var \eZ\Publish\SPI\IO\Handler
     */
    protected $ioHandler;

    /**
     * @var array
     */
    protected $settings;


    /**
     * Setups service with reference to repository object that created it & corresponding handler
     *
     * @param \eZ\Publish\SPI\IO\Handler $handler
     * @param array $settings
     */
    public function __construct( Handler $handler, array $settings = array() )
    {
        $this->ioHandler = $handler;
        // Union makes sure default settings are ignored if provided in argument
        $this->settings = $settings + array(
            //'defaultSetting' => array(),
        );
    }

    /**
     * Creates a BinaryFileCreateStruct object from the uploaded file $uploadedFile
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentException When given an invalid uploaded file
     *
     * @param array $uploadedFile The $_POST hash of an uploaded file
     *
     * @return \eZ\Publish\Core\IO\Values\BinaryFileCreateStruct
     */
    public function newBinaryCreateStructFromUploadedFile( array $uploadedFile )
    {
        if ( !is_string( $uploadedFile['tmp_name'] ) || empty( $uploadedFile['tmp_name'] ) )
            throw new InvalidArgumentException( "uploadedFile", "uploadedFile['tmp_name'] does not exist or has invalid value" );

        if ( !is_uploaded_file( $uploadedFile['tmp_name'] ) || !is_readable( $uploadedFile['tmp_name'] ) )
            throw new InvalidArgumentException( "uploadedFile", "file was not uploaded or is unreadable" );

        $fileHandle = fopen( $uploadedFile['tmp_name'], 'rb' );
        if ( $fileHandle === false )
            throw new InvalidArgumentException( "uploadedFile", "failed to get file resource" );

        $binaryCreateStruct = new BinaryFileCreateStruct();
        $binaryCreateStruct->mimeType = $uploadedFile['type'];
        $binaryCreateStruct->uri = $uploadedFile['tmp_name'];
        $binaryCreateStruct->originalFileName = $uploadedFile['name'];
        $binaryCreateStruct->size = $uploadedFile['size'];
        $binaryCreateStruct->inputStream = $fileHandle;

        return $binaryCreateStruct;
    }

    /**
     * Creates a BinaryFileCreateStruct object from $localFile
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentException When given a non existing / unreadable file
     *
     * @param string $localFile Path to local file
     *
     * @return \eZ\Publish\Core\IO\Values\BinaryFileCreateStruct
     */
    public function newBinaryCreateStructFromLocalFile( $localFile )
    {
        if ( empty( $localFile ) || !is_string( $localFile ) )
            throw new InvalidArgumentException( "localFile", "localFile has an invalid value" );

        if ( !is_file( $localFile ) || !is_readable( $localFile ) )
            throw new InvalidArgumentException( "localFile", "file does not exist or is unreadable: {$localFile}" );

        $fileHandle = fopen( $localFile, 'rb' );
        if ( $fileHandle === false )
            throw new InvalidArgumentException( "localFile", "failed to get file resource" );

        $binaryCreateStruct = new BinaryFileCreateStruct();
        $binaryCreateStruct->mimeType = mime_content_type( $localFile );
        $binaryCreateStruct->uri = $localFile;
        $binaryCreateStruct->originalFileName = basename( $localFile );
        $binaryCreateStruct->size = filesize( $localFile );
        $binaryCreateStruct->inputStream = $fileHandle;

        return $binaryCreateStruct;
    }

    /**
     * Creates a binary file in the repository
     *
     * @param \eZ\Publish\Core\IO\Values\BinaryFileCreateStruct $binaryFileCreateStruct
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue
     *
     * @return \eZ\Publish\Core\IO\Values\BinaryFile The created BinaryFile object
     */
    public function createBinaryFile( BinaryFileCreateStruct $binaryFileCreateStruct )
    {
        if ( empty( $binaryFileCreateStruct->mimeType ) || !is_string( $binaryFileCreateStruct->mimeType ) )
            throw new InvalidArgumentValue( "mimeType", "invalid mimeType value", "BinaryFileCreateStruct" );

        if ( empty( $binaryFileCreateStruct->uri ) || !is_string( $binaryFileCreateStruct->uri ) )
            throw new InvalidArgumentValue( "uri", $binaryFileCreateStruct->uri, "BinaryFileCreateStruct" );

        if ( empty( $binaryFileCreateStruct->originalFileName ) || !is_string( $binaryFileCreateStruct->originalFileName ) )
            throw new InvalidArgumentValue( "originalFileName", $binaryFileCreateStruct->originalFileName, "BinaryFileCreateStruct" );

        if ( !is_int( $binaryFileCreateStruct->size ) || $binaryFileCreateStruct->size < 0 )
            throw new InvalidArgumentValue( "size", $binaryFileCreateStruct->size, "BinaryFileCreateStruct" );

        if ( !is_resource( $binaryFileCreateStruct->inputStream ) )
            throw new InvalidArgumentValue( "inputStream", "property is not a file resource", "BinaryFileCreateStruct" );

        $spiBinaryCreateStruct = $this->buildSPIBinaryFileCreateStructObject( $binaryFileCreateStruct );

        $spiBinaryFile = $this->ioHandler->create( $spiBinaryCreateStruct );

        return $this->buildDomainBinaryFileObject( $spiBinaryFile );
    }

    /**
     * Deletes the BinaryFile with $path
     *
     * @param \eZ\Publish\Core\IO\Values\BinaryFile $binaryFile
     *
     * @throws InvalidArgumentValue
     */
    public function deleteBinaryFile( BinaryFile $binaryFile )
    {
        if ( empty( $binaryFile->id ) || !is_string( $binaryFile->id ) )
            throw new InvalidArgumentValue( "id", $binaryFile->id, "BinaryFile" );

        $this->ioHandler->delete( $binaryFile->id );
    }

    /**
     * Loads the binary file with $id
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue If the id is invalid
     * @throws \eZ\Publish\Core\Base\Exceptions\NotFoundException If no file identified by $path exists
     *
     * @param string $binaryFileId
     *
     * @return \eZ\Publish\Core\IO\Values\BinaryFile|bool the file, or false if it doesn't exist
     */
    public function loadBinaryFile( $binaryFileId )
    {
        if ( empty( $binaryFileId ) || !is_string( $binaryFileId ) )
            throw new InvalidArgumentValue( "binaryFileId", $binaryFileId );

        try
        {
            $spiBinaryFile = $this->ioHandler->load( $binaryFileId );
        }
        catch ( NotFoundException $e )
        {
            return false;
        }

        return $this->buildDomainBinaryFileObject( $spiBinaryFile );
    }

    /**
     * Returns a read (mode: rb) file resource to the binary file identified by $path
     *
     * @param \eZ\Publish\Core\IO\Values\BinaryFile $binaryFile
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue
     *
     * @return resource
     */
    public function getFileInputStream( BinaryFile $binaryFile )
    {
        if ( empty( $binaryFile->id ) || !is_string( $binaryFile->id ) )
            throw new InvalidArgumentValue( "id", $binaryFile->id, "BinaryFile" );

        return $this->ioHandler->getFileResource( $binaryFile->id );
    }

    /**
     * Returns the content of the binary file
     *
     * @param \eZ\Publish\Core\IO\Values\BinaryFile $binaryFile
     *
     * @throws \eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue

     * @return string
     */
    public function getFileContents( BinaryFile $binaryFile )
    {
        if ( empty( $binaryFile->id ) || !is_string( $binaryFile->id ) )
            throw new InvalidArgumentValue( "id", $binaryFile->id, "BinaryFile" );

        //@todo: is binary file ID equal to file path?
        return $this->ioHandler->getFileContents( $binaryFile->id );
    }

    /**
     * Generates SPI BinaryFileCreateStruct object from provided API BinaryFileCreateStruct object
     *
     * @param \eZ\Publish\Core\IO\Values\BinaryFileCreateStruct $binaryFileCreateStruct
     *
     * @return \eZ\Publish\SPI\IO\BinaryFileCreateStruct
     */
    protected function buildSPIBinaryFileCreateStructObject( BinaryFileCreateStruct $binaryFileCreateStruct )
    {
        $spiBinaryCreateStruct = new SPIBinaryFileCreateStruct();

        $spiBinaryCreateStruct->path = $binaryFileCreateStruct->uri;
        $spiBinaryCreateStruct->size = $binaryFileCreateStruct->size;
        $spiBinaryCreateStruct->mimeType = $binaryFileCreateStruct->mimeType;
        $spiBinaryCreateStruct->originalFile = $binaryFileCreateStruct->originalFileName;
        $spiBinaryCreateStruct->setInputStream( $binaryFileCreateStruct->inputStream );

        return $spiBinaryCreateStruct;
    }

    /**
     * Generates API BinaryFile object from provided SPI BinaryFile object
     *
     * @param \eZ\Publish\SPI\IO\BinaryFile $spiBinaryFile
     *
     * @return \eZ\Publish\Core\IO\Values\BinaryFile
     */
    protected function buildDomainBinaryFileObject( SPIBinaryFile $spiBinaryFile )
    {
        return new BinaryFile(
            array(
                //@todo is setting the id of file to path correct?
                'id' => $spiBinaryFile->path,
                'size' => (int)$spiBinaryFile->size,
                'mtime' => $spiBinaryFile->mtime,
                'ctime' => $spiBinaryFile->ctime,
                'mimeType' => $spiBinaryFile->mimeType,
                'uri' => $spiBinaryFile->uri,
                'originalFile' => $spiBinaryFile->originalFile
            )
        );
    }
}
