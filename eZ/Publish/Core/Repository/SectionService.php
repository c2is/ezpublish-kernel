<?php
/**
 * @package eZ\Publish\Core\Repository
 */
namespace eZ\Publish\Core\Repository;

use eZ\Publish\API\Repository\Values\Content\SectionCreateStruct,
    eZ\Publish\API\Repository\Values\Content\Content,
    eZ\Publish\API\Repository\Values\Content\ContentInfo,
    eZ\Publish\API\Repository\Values\Content\Section,
    eZ\Publish\API\Repository\Values\Content\Location,
    eZ\Publish\API\Repository\Values\Content\SectionUpdateStruct,

    eZ\Publish\API\Repository\SectionService as SectionServiceInterface,
    eZ\Publish\API\Repository\Repository as RepositoryInterface,
    eZ\Publish\SPI\Persistence\Handler,

    ezp\Base\Exception\NotFound,
    eZ\Publish\Core\Base\Exceptions\InvalidArgumentValue,
    eZ\Publish\Core\Base\Exceptions\IllegalArgumentException,
    eZ\Publish\Core\Base\Exceptions\NotFoundException,
    eZ\Publish\Core\Base\Exceptions\BadStateException,
    eZ\Publish\API\Repository\Exceptions\UnauthorizedException;

/**
 * Section service, used for section operations
 *
 * @package eZ\Publish\Core\Repository
 */
class SectionService implements SectionServiceInterface
{
    /**
     * @var \eZ\Publish\API\Repository\Repository
     */
    protected $repository;

    /**
     * @var \eZ\Publish\SPI\Persistence\Handler
     */
    protected $persistenceHandler;

    /**
     * Setups service with reference to repository object that created it & corresponding handler
     *
     * @param \eZ\Publish\API\Repository\Repository  $repository
     * @param \eZ\Publish\SPI\Persistence\Handler $handler
     */
    public function __construct( RepositoryInterface $repository, Handler $handler )
    {
        $this->repository = $repository;
        $this->persistenceHandler = $handler;
    }

    /**
     * Creates a new Section in the content repository
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to create a section
     * @throws \eZ\Publish\API\Repository\Exceptions\IllegalArgumentException If the new identifier in $sectionCreateStruct already exists
     *
     * @param \eZ\Publish\API\Repository\Values\Content\SectionCreateStruct $sectionCreateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section The newly created section
     */
    public function createSection( SectionCreateStruct $sectionCreateStruct )
    {
        if ( !is_string( $sectionCreateStruct->name ) )
            throw new InvalidArgumentValue( "name", $sectionCreateStruct->name, "SectionCreateStruct" );

        if ( !is_string( $sectionCreateStruct->identifier ) )
            throw new InvalidArgumentValue( "identifier", $sectionCreateStruct->identifier, "SectionCreateStruct" );

        try
        {
            $existingSection = $this->loadSectionByIdentifier( $sectionCreateStruct->identifier );
            if ( $existingSection !== null )
                throw new IllegalArgumentException( "identifier", $sectionCreateStruct->identifier );
        }
        catch ( NotFoundException $e ) {}

        $createdSection = $this->persistenceHandler->sectionHandler()->create(
            $sectionCreateStruct->name,
            $sectionCreateStruct->identifier
        );

        return new Section( array(
            'id'         => $createdSection->id,
            'identifier' => $createdSection->identifier,
            'name'       => $createdSection->name
        ) );
    }

    /**
     * Updates the given section in the content repository
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to create a section
     * @throws \eZ\Publish\API\Repository\Exceptions\IllegalArgumentException If the new identifier already exists (if set in the update struct)
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     * @param \eZ\Publish\API\Repository\Values\Content\SectionUpdateStruct $sectionUpdateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    public function updateSection( Section $section, SectionUpdateStruct $sectionUpdateStruct )
    {
        if ( !is_numeric( $section->id ) )
            throw new InvalidArgumentValue( "id", $section->id, "Section" );

        if ( $sectionUpdateStruct->name !== null && !is_string( $sectionUpdateStruct->name ) )
            throw new InvalidArgumentValue( "name", $section->name, "Section" );

        if ( $sectionUpdateStruct->identifier !== null && !is_string( $sectionUpdateStruct->identifier ) )
            throw new InvalidArgumentValue( "identifier", $section->identifier, "Section" );

        if ( $sectionUpdateStruct->identifier !== null )
        {
            try
            {
                $existingSection = $this->loadSectionByIdentifier( $sectionUpdateStruct->identifier );
                if ( $existingSection !== null )
                    throw new IllegalArgumentException( "identifier", $sectionUpdateStruct->identifier );
            }
            catch ( NotFoundException $e ) {}
        }

        $loadedSection = $this->loadSection( $section->id );

        $spiSection = $this->persistenceHandler->sectionHandler()->update(
            $loadedSection->id,
            $sectionUpdateStruct->name !== null ? $sectionUpdateStruct->name : $loadedSection->name,
            $sectionUpdateStruct->identifier !== null ? $sectionUpdateStruct->identifier : $loadedSection->identifier
        );

        return new Section( array(
            'id'         => $spiSection->id,
            'identifier' => $spiSection->identifier,
            'name'       => $spiSection->name
        ) );
    }

    /**
     * Loads a Section from its id ($sectionId)
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if section could not be found
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to read a section
     *
     * @param int $sectionId
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    public function loadSection( $sectionId )
    {
        if ( !is_numeric( $sectionId ) )
            throw new InvalidArgumentValue( "sectionId", $sectionId );

        try
        {
            $section = $this->persistenceHandler->sectionHandler()->load( $sectionId );
        }
        catch ( NotFound $e )
        {
            throw new NotFoundException( "section", $sectionId, $e );
        }

        return new Section( array(
            'id'         => $section->id,
            'identifier' => $section->identifier,
            'name'       => $section->name
        ) );
    }

    /**
     * Loads all sections
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to read a section
     *
     * @return array of {@link \eZ\Publish\API\Repository\Values\Content\Section}
     */
    public function loadSections()
    {
        $allSections = $this->persistenceHandler->sectionHandler()->loadAll();
        if ( $allSections === null )
            return array();

        $returnArray = array();

        if ( !is_array( $allSections ) )
            $allSections = array( $allSections );

        foreach ( $allSections as $section )
        {
            $returnArray[] = new Section( array(
                'id'         => $section->id,
                'identifier' => $section->identifier,
                'name'       => $section->name
            ) );
        }

        return $returnArray;
    }

    /**
     * Loads a Section from its identifier ($sectionIdentifier)
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException if section could not be found
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user user is not allowed to read a section
     *
     * @param string $sectionIdentifier
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Section
     */
    public function loadSectionByIdentifier( $sectionIdentifier )
    {
        if ( !is_string( $sectionIdentifier ) )
            throw new InvalidArgumentValue( "sectionIdentifier", $sectionIdentifier );

        try
        {
            $section = $this->persistenceHandler->sectionHandler()->loadByIdentifier( $sectionIdentifier );
        }
        catch ( NotFound $e )
        {
            throw new NotFoundException( "section", $sectionIdentifier, $e );
        }

        return new Section( array(
            'id'         => $section->id,
            'identifier' => $section->identifier,
            'name'       => $section->name
        ) );
    }

    /**
     * Counts the contents which $section is assigned to
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     *
     * @return int
     */
    public function countAssignedContents( Section $section )
    {
        if ( !is_numeric( $section->id ) )
            throw new InvalidArgumentValue( "id", $section->id, "Section" );

        return $this->persistenceHandler->sectionHandler()->assignmentsCount( $section->id );
    }

    /**
     * assigns the content to the given section
     * this method overrides the current assigned section
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If user does not have access to view provided object
     *
     * @param \eZ\Publish\API\Repository\Values\Content\ContentInfo $contentInfo
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     */
    public function assignSection( ContentInfo $contentInfo, Section $section )
    {
        if ( !is_numeric( $contentInfo->contentId ) )
            throw new InvalidArgumentValue( "contentId", $contentInfo->contentId, "ContentInfo" );

        if ( !is_numeric( $section->id ) )
            throw new InvalidArgumentValue( "id", $section->id, "Section" );

        $loadedContentInfo = $this->repository->getContentService()->loadContentInfo( $contentInfo->contentId );
        $loadedSection = $this->loadSection( $section->id );

        $this->persistenceHandler->sectionHandler()->assign( $loadedSection->id, $loadedContentInfo->contentId );
    }

    /**
     * Deletes $section from content repository
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\NotFoundException If the specified section is not found
     * @throws \eZ\Publish\API\Repository\Exceptions\UnauthorizedException If the current user is not allowed to delete a section
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException If section can not be deleted
     *         because it is still assigned to some contents.
     *
     * @param \eZ\Publish\API\Repository\Values\Content\Section $section
     */
    public function deleteSection( Section $section )
    {
        if ( !is_numeric( $section->id ) )
            throw new InvalidArgumentValue( "id", $section->id, "Section" );

        $loadedSection = $this->loadSection( $section->id );

        if ( $this->countAssignedContents( $loadedSection ) > 0 )
            throw new BadStateException( "section" );

        $this->persistenceHandler->sectionHandler()->delete( $loadedSection->id );
    }

    /**
     * instantiates a new SectionCreateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\SectionCreateStruct
     */
    public function newSectionCreateStruct()
    {
        return new SectionCreateStruct();
    }

    /**
     * instantiates a new SectionUpdateStruct
     *
     * @return \eZ\Publish\API\Repository\Values\Content\SectionUpdateStruct
     */
    public function newSectionUpdateStruct()
    {
        return new SectionUpdateStruct();
    }
}
