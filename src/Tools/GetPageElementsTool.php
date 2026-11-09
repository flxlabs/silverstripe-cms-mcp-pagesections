<?php


namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;
use FLXLabs\PageSections\PageSection;

class GetPageElementsTool extends AbstractTool
{
    public function getName(): string
    {
        return 'get_page_elements';
    }

    public function getDescription(): string
    {
        return 'Collect all PageElements from all PageSections of a given record. '
            . 'Returns the full element tree (including nested child elements) with their '
            . 'field values. Useful for inspecting the content structure of a page without '
            . 'knowing the element IDs in advance. For Fluent-localized classes, pass '
            . '"locale" to read a specific locale (e.g. "fr_FR").';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'A class returned by list_classes'],
                'id' => ['type' => 'integer', 'description' => 'Record ID'],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Optional locale code for Fluent-localized classes (e.g. "fr_FR"). '
                        . 'If omitted, uses the current locale.',
                ],
                'subsiteId' => [
                    'type' => 'integer',
                    'description' => 'Optional subsite ID to query within. If omitted, uses the current subsite.',
                ],
            ],
            'required' => ['class', 'id'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $class = $this->requireExposableClass($arguments);
        $id = $this->requireInt($arguments, 'id');
        $locale = $arguments['locale'] ?? null;
        $subsiteId = isset($arguments['subsiteId']) ? (int) $arguments['subsiteId'] : null;

        return $this->withSubsite($subsiteId, function () use ($arguments, $actingMember, $class, $id, $locale) {
            return $this->withLocale($class, $locale, function () use ($arguments, $actingMember, $class, $id) {
                $record = DataObject::get($class)->byID($id);
                if (!$record) {
                    throw new ToolException("No $class record found with id $id");
                }

                if (!$record->canView($actingMember)) {
                    throw new ToolException("Permission denied: this member cannot view this $class record");
                }

                $sections = $this->findSections($record);
                $sectionList = iterator_to_array($sections);

                $hierarchicalSections = array_map(function ($section) {
                    $elements = [];
                    foreach ($section->Elements()->Sort('SortOrder') as $element) {
                        $elements[] = $this->collectElement($element);
                    }
                    return [
                        'id' => $section->ID,
                        'name' => $section->__Name,
                        'elements' => $elements,
                    ];
                }, $sectionList);

                return $this->jsonResult([
                    'class' => $class,
                    'id' => $id,
                    'sections' => $hierarchicalSections,
                ]);
            });
        });
    }

    /**
     * Find all PageSections belonging to a record.
     */
    /** @return \SilverStripe\ORM\DataList<\FLXLabs\PageSections\PageSection> */
    private function findSections(DataObject $record): \SilverStripe\ORM\DataList
    {
        return PageSection::get()->filter([
            '__ParentClass' => $record->ClassName,
            '__ParentID' => $record->ID,
        ]);
    }

    /**
     * Recursively collect an element and all its children.
     */
    private function collectElement(PageElement $element): array
    {
        $class = $element->ClassName;
        $introspector = FieldIntrospector::singleton();

        $fields = [];
        foreach ($introspector->describeEditableFields($class) as $name => $meta) {
            $fields[$name] = $element->getField($name);
        }

        $children = [];
        foreach ($element->Children() as $child) {
            $children[] = $this->collectElement($child);
        }

        return [
            'id' => $element->ID,
            'className' => $class,
            'name' => $element->Name,
            'fields' => $fields,
            'children' => $children,
        ];
    }
}
