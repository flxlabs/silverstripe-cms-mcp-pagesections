<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\ORM\DataObject;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use FLXLabs\CmsMcp\FieldIntrospector;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;

class CreatePageElementTool extends AbstractTool
{
    public function getName(): string
    {
        return 'create_page_element';
    }

    public function getDescription(): string
    {
        return 'Create a new PageElement (e.g. JumboTeaserChapter, TextElement, TeaserElement) '
            . 'and optionally attach it as a child to an existing parent element. '
            . 'Pass "parentId" (and optionally "parentClass") to validate the element is allowed — '
            . 'the tool checks getAllowedPageElements() against the parent DataObject and rejects '
            . 'disallowed element types. If "parentElementId" is provided, validation is against '
            . 'that parent element\'s allowed children. Otherwise, validation is against the parent '
            . 'DataObject (Page, Vehicle, etc.) for the given section. '
            . 'Writes to the draft stage only — call publish_record on the parent page '
            . 'to make the change live. For Fluent-localized classes, pass "locale" to create '
            . 'in a specific locale.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'class' => ['type' => 'string', 'description' => 'PageElement subclass to create, e.g. JumboTeaserChapter'],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Map of field name to value (Caption, Text, Color, etc.)',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.).',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided.',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to check against (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main". Only used when parentId is provided without parentElementId.',
                ],
                'parentElementId' => ['type' => 'integer', 'description' => 'Optional: ID of the parent PageElement to add this as a child'],
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
            'required' => ['class', 'fields'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        $elementClass = $this->requireExposableClass($arguments);
        $locale = $arguments['locale'] ?? null;
        $subsiteId = isset($arguments['subsiteId']) ? (int) $arguments['subsiteId'] : null;

        // Verify it's a PageElement subclass
        if (!is_subclass_of($elementClass, PageElement::class)) {
            throw new ToolException("Class \"$elementClass\" is not a PageElement subclass.");
        }

        // Validate element is allowed (if parentId or parentElementId provided)
        $parentId = $arguments['parentId'] ?? null;
        $parentClass = $arguments['parentClass'] ?? SiteTree::class;
        $sectionName = $arguments['sectionName'] ?? 'Main';
        $parentElementId = $arguments['parentElementId'] ?? null;

        if ($parentElementId) {
            // Check against the parent element's allowed children
            $parent = PageElement::get()->byID($parentElementId);
            if (!$parent) {
                throw new ToolException("No PageElement found with id $parentElementId");
            }
            $allowed = $parent->getAllowedPageElements();
            if (!in_array($elementClass, $allowed, true)) {
                throw new ToolException(
                    "\"$elementClass\" is not allowed as a child of this element. "
                        . 'Allowed element types: ' . implode(', ', $allowed)
                );
            }
        } elseif ($parentId) {
            // Check against the parent DataObject's allowed elements for the section
            $parentObject = $parentClass::get()->byID((int) $parentId);
            if (!$parentObject) {
                throw new ToolException("No {$parentClass} record found with id $parentId");
            }
            if (!method_exists($parentObject, 'getAllowedPageElements')) {
                throw new ToolException(
                    "Class \"{$parentObject->ClassName}\" does not have getAllowedPageElements() method."
                );
            }
            $allowed = $parentObject->getAllowedPageElements($sectionName);
            if (!in_array($elementClass, $allowed, true)) {
                throw new ToolException(
                    "\"$elementClass\" is not allowed in section \"$sectionName\" of this object. "
                        . 'Allowed element types: ' . implode(', ', $allowed)
                );
            }
        }

        return $this->withSubsite($subsiteId, function () use ($arguments, $actingMember, $elementClass, $locale, $parentId) {
            return $this->withLocale($elementClass, $locale, function () use ($arguments, $actingMember, $elementClass, $parentId) {
                if (!singleton($elementClass)->canCreate($actingMember)) {
                    throw new ToolException("Permission denied: cannot create $elementClass");
                }

                if (!isset($arguments['fields']) || !is_array($arguments['fields'])) {
                    throw new ToolException('Missing required argument "fields" (an object of fieldName => value)');
                }

                $editable = FieldIntrospector::singleton()->describeEditableFields($elementClass);
                $invalid = array_diff(array_keys($arguments['fields']), array_keys($editable));
                if ($invalid) {
                    throw new ToolException(
                        'Not editable: ' . implode(', ', $invalid)
                            . '. Call list_fields to see valid fields.'
                    );
                }

                // Preserve sort order during MCP writes

                // Create the element
                $element = $elementClass::create();

                foreach ($arguments['fields'] as $name => $value) {
                    $element->setField($name, $this->castValue($editable[$name]['type'], $value));
                }

                $element->writeToStage(Versioned::DRAFT);

                // Optionally add as child to a parent element
                $parentElementId = $arguments['parentElementId'] ?? null;
                if ($parentElementId) {
                    $parent = PageElement::get()->byID($parentElementId);
                    if (!$parent) {
                        throw new ToolException("No PageElement found with id $parentElementId");
                    }
                    $parent->Children()->Add($element);
                    // Re-write parent to trigger counter update
                    if ($parent->hasExtension(Versioned::class)) {
                        $parent->writeToStage(Versioned::DRAFT);
                    }
                }

                return $this->jsonResult([
                    'class' => $elementClass,
                    'id' => $element->ID,
                    'fields' => array_keys($arguments['fields']),
                    'parentId' => $parentElementId,
                ]);
            });
        });
    }

    private function castValue(string $type, $value)
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            default => (string) $value,
        };
    }
}
