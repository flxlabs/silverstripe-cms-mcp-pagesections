<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\MCP\AbstractTool;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageElement;
use FLXLabs\PageSections\PageSection;

/**
 * Shared argument handling and target resolution for tools that operate on
 * PageElements inside a PageSection (add / copy / check).
 *
 * A target is either a PageSection directly (via "sectionId"), or a parent
 * DataObject + section name (via "parentId"/"parentIds" + "parentClass" +
 * "sectionName"), mirroring how PageSectionsExtension exposes sections on the
 * parent ("PageSectionMain()" reads, "getPageSectionMain()" reads-or-creates).
 */
abstract class SectionElementToolBase extends AbstractTool
{
    /**
     * Parse the shared targeting arguments.
     *
     * @return array{
     *     elementId: int,
     *     sectionId: int|null,
     *     parentIds: int[],
     *     parentClass: string,
     *     sectionName: string
     * }
     */
    protected function parseSectionArguments(array $arguments): array
    {
        $elementId = $this->requireInt($arguments, 'elementId');

        $hasSectionId = isset($arguments['sectionId']) && is_numeric($arguments['sectionId']);
        $parentId = (isset($arguments['parentId']) && is_numeric($arguments['parentId']))
            ? (int) $arguments['parentId']
            : null;
        $parentIdsRaw = $arguments['parentIds'] ?? [];
        if (is_numeric($parentIdsRaw)) {
            $parentIdsRaw = [$parentIdsRaw];
        }
        $parentIds = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, (array) $parentIdsRaw),
            static fn (int $id): bool => $id > 0
        ));

        if ($hasSectionId && $parentIds) {
            throw new ToolException('Provide either "sectionId" or "parentId"/"parentIds", not both');
        }
        if (!$hasSectionId && $parentId !== null && $parentIds) {
            throw new ToolException('Provide either "parentId" (single) or "parentIds" (batch), not both');
        }
        if (!$hasSectionId && $parentId === null && !$parentIds) {
            throw new ToolException(
                'Either "sectionId" or "parentId"/"parentIds" (with optional "parentClass") is required'
            );
        }

        return [
            'elementId' => $elementId,
            'sectionId' => $hasSectionId ? (int) $arguments['sectionId'] : null,
            'parentIds' => $parentIds ?: ($parentId !== null ? [$parentId] : []),
            'parentClass' => $arguments['parentClass'] ?? SiteTree::class,
            'sectionName' => $arguments['sectionName'] ?? 'Main',
        ];
    }

    /**
     * Look up the element to operate on.
     */
    protected function getElement(int $elementId): PageElement
    {
        $element = PageElement::get()->byID($elementId);
        if (!$element) {
            throw new ToolException("No PageElement found with id $elementId");
        }
        return $element;
    }

    /**
     * Resolve the target PageSection(s) from the parsed arguments.
     *
     * @param array $parsed Result of parseSectionArguments()
     * @param bool $createIfMissing When resolving from a parent, create the section if it does not exist yet
     * @return array[] Each target: ['section' => PageSection|null, 'parent' => DataObject|null]
     */
    protected function resolveSections(array $parsed, bool $createIfMissing, Member $actingMember): array
    {
        if ($parsed['sectionId'] !== null) {
            $section = PageSection::get()->byID($parsed['sectionId']);
            if (!$section) {
                throw new ToolException("No PageSection found with id {$parsed['sectionId']}");
            }
            if (!$section->canView($actingMember)) {
                throw new ToolException('Permission denied: cannot view this PageSection');
            }
            return [['section' => $section, 'parent' => null]];
        }

        $targets = [];
        foreach ($parsed['parentIds'] as $parentId) {
            $parentObject = $parsed['parentClass']::get()->byID($parentId);
            if (!$parentObject) {
                throw new ToolException("No {$parsed['parentClass']} record found with id $parentId");
            }
            if (!$parentObject->canView($actingMember)) {
                throw new ToolException("Permission denied: cannot view {$parsed['parentClass']} #$parentId");
            }

            // Mirror PageSectionsExtension: section names come from the class's
            // "page_sections" config, defaulting to ["Main"].
            $availableSections = Config::inst()->get(
                $parentObject->ClassName,
                'page_sections',
                Config::EXCLUDE_EXTRA_SOURCES
            );
            if (!$availableSections) {
                $availableSections = ['Main'];
            }
            if (!in_array($parsed['sectionName'], $availableSections, true)) {
                throw new ToolException(
                    "Class \"{$parentObject->ClassName}\" has no section \"{$parsed['sectionName']}".
                    '". Available sections: ' . implode(', ', $availableSections)
                );
            }

            // "PageSection{X}()" is the has_one accessor (read-only);
            // "getPageSection{X}()" also restores/creates the section if missing.
            $getter = ($createIfMissing ? 'getPageSection' : 'PageSection') . $parsed['sectionName'];
            if (!$parentObject->hasMethod($getter)) {
                throw new ToolException(
                    "Class \"{$parentObject->ClassName}\" does not expose section \"{$parsed['sectionName']}\" "
                    . '(is it using FLXLabs\\PageSections\\PageSectionsExtension?)'
                );
            }

            $section = $parentObject->$getter();
            if (!$section || !$section->ID) {
                if ($createIfMissing) {
                    throw new ToolException(
                        "Could not resolve section \"{$parsed['sectionName']}\" on "
                        . "{$parsed['parentClass']} #$parentId"
                    );
                }
                // Dry-run: report the missing section instead of failing
                $targets[] = ['section' => null, 'parent' => $parentObject];
                continue;
            }

            $targets[] = ['section' => $section, 'parent' => $parentObject];
        }

        return $targets;
    }

    /**
     * Validate that the element type is allowed on the given parent + section.
     * Throws a ToolException when the parent does not support page sections or
     * when the element type is not allowed. Does nothing when $parent is null
     * (no parent involved, e.g. a bare "sectionId" target).
     */
    protected function assertElementAllowed(PageElement $element, ?DataObject $parent, string $sectionName): void
    {
        if ($parent === null) {
            return;
        }

        if (!$parent->hasMethod('getAllowedPageElements')) {
            throw new ToolException(
                "Class \"{$parent->ClassName}\" does not have getAllowedPageElements() method."
            );
        }

        $allowed = $parent->getAllowedPageElements($sectionName);
        if (!in_array($element->ClassName, $allowed, true)) {
            throw new ToolException(
                "\"{$element->ClassName}\" is not allowed in section \"$sectionName\" of "
                . "{$parent->ClassName} #{$parent->ID}. Allowed element types: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * Resolve the "parentElementId" target (nested operations).
     */
    protected function resolveParentElement(array $arguments, Member $actingMember): PageElement
    {
        $id = $this->requireInt($arguments, 'parentElementId');
        $element = PageElement::get()->byID($id);
        if (!$element) {
            throw new ToolException("No PageElement found with id $id");
        }
        if (!$element->canView($actingMember)) {
            throw new ToolException('Permission denied: cannot view parent element #' . $id);
        }
        return $element;
    }

    /**
     * Validate that $child's class is allowed as a direct child of $parent
     * (element-level check, e.g. a TextElement inside a JumboTeaserChapter).
     */
    protected function assertChildAllowed(PageElement $child, PageElement $parent): void
    {
        $allowed = $parent->getAllowedPageElements();
        if (!in_array($child->ClassName, $allowed, true)) {
            throw new ToolException(
                "\"{$child->ClassName}\" is not allowed as a child of {$parent->ClassName} #{$parent->ID}. "
                . 'Allowed element types: ' . implode(', ', $allowed)
            );
        }
    }

    /**
     * True when $element is $start itself or an ancestor of it, i.e. adding
     * $element as a child of $start would create a cycle.
     */
    protected function wouldCreateCycle(PageElement $start, PageElement $element): bool
    {
        if ($start->ID === $element->ID) {
            return true;
        }

        // An element can have several parents (Children is a many_many), so walk
        // up the whole ancestor graph breadth-first with a visited set.
        $visited = [$start->ID => true];
        $queue = [$start];
        while ($queue) {
            $current = array_shift($queue);
            foreach ($current->Parents() as $ancestor) {
                if (!$ancestor || !$ancestor->ID || isset($visited[$ancestor->ID])) {
                    continue;
                }
                if ($ancestor->ID === $element->ID) {
                    return true;
                }
                $visited[$ancestor->ID] = true;
                $queue[] = $ancestor;
            }
        }
        return false;
    }

    /**
     * Ensure exactly one target mode was provided: a section target
     * ("sectionId" / "parentId" / "parentIds") or an element target
     * ("parentElementId"). Returns "section" or "element".
     *
     * Callers must still enforce that at least one target was given.
     */
    protected function requireSingleTargetMode(array $arguments): string
    {
        $hasSectionTarget = isset($arguments['sectionId'])
            || isset($arguments['parentId'])
            || isset($arguments['parentIds']);
        $hasElementTarget = isset($arguments['parentElementId']);

        if ($hasSectionTarget && $hasElementTarget) {
            throw new ToolException(
                'Provide either a section target ("sectionId" / "parentId" / "parentIds") '
                . 'or "parentElementId", not both'
            );
        }
        return $hasElementTarget ? 'element' : 'section';
    }
}
