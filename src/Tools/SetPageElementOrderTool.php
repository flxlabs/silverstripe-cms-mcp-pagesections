<?php

namespace FLXLabs\CmsMcpPagesections\Tools;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\RelationList;
use SilverStripe\Security\Member;
use FLXLabs\CmsMcp\MCP\ToolException;
use FLXLabs\PageSections\PageSection;

class SetPageElementOrderTool extends SectionElementToolBase
{
    public function getName(): string
    {
        return 'set_page_element_order';
    }

    public function getDescription(): string
    {
        return 'Reorder the PageElements inside ONE container — either a PageSection\'s root Elements '
            . 'list (target it via "sectionId", or via "parentId" + optional "parentClass" + '
            . '"sectionName") or a nested PageElement\'s Children list (target it via '
            . '"parentElementId"). "elementIds" must be the COMPLETE desired order: an array '
            . 'containing every current member of the container exactly once, in the new order. '
            . 'Partial orders are rejected — a member missing from the list or an ID that does not '
            . 'belong to the container fails the whole call before anything is written. This is a '
            . 'pure reorder: no counters are bumped and no new version is created (the same '
            . 'mechanism the CMS drag-and-drop uses). "parentIds" (batch) is not supported. '
            . 'Writes to the draft stage only — call publish_record on the root page to make the '
            . 'change live.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'elementIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'The COMPLETE desired order: every current member of the container '
                        . 'exactly once, in the new order.',
                ],
                'sectionId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the PageSection whose root element order to set. '
                        . 'If omitted, the section is resolved from "parentId" + "parentClass" + "sectionName".',
                ],
                'parentId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent DataObject (Page, Vehicle, etc.). '
                        . 'Used to resolve the section when "sectionId" is omitted.',
                ],
                'parentClass' => [
                    'type' => 'string',
                    'description' => 'Optional: ClassName of the parent DataObject (e.g. SiteTree, Vehicle). '
                        . 'Defaults to SiteTree if not provided.',
                ],
                'sectionName' => [
                    'type' => 'string',
                    'description' => 'Optional: Name of the section to resolve (e.g. "Main", "FooterNav"). '
                        . 'Defaults to "Main".',
                ],
                'parentElementId' => [
                    'type' => 'integer',
                    'description' => 'Optional: ID of the parent PageElement whose child order to set. '
                        . 'Mutually exclusive with "sectionId" / "parentId".',
                ],
            ],
            'required' => ['elementIds'],
        ];
    }

    public function call(array $arguments, Member $actingMember): array
    {
        if (isset($arguments['parentIds'])) {
            throw new ToolException(
                '"parentIds" (batch) is not supported: the order is set for a single container. '
                . 'Use "sectionId" or "parentId" (section) or "parentElementId" (nested) instead.'
            );
        }

        $mode = $this->requireSingleTargetMode($arguments);
        $desired = $this->parseDesiredOrder($arguments['elementIds'] ?? null);

        if ($mode === 'element') {
            $parent = $this->resolveParentElement($arguments, $actingMember);
            if (!$parent->canEdit($actingMember)) {
                throw new ToolException('Permission denied: cannot edit parent element #' . $parent->ID);
            }

            return $this->jsonResult(
                $this->reorder($parent->Children(), $desired) + [
                    'parentElementId' => $parent->ID,
                    'parentElementClass' => $parent->ClassName,
                ]
            );
        }

        // Section mode: resolve the single target section.
        $sectionId = (isset($arguments['sectionId']) && is_numeric($arguments['sectionId']))
            ? (int) $arguments['sectionId']
            : null;
        $extra = [];
        $sectionOwner = null;
        if ($sectionId !== null) {
            $section = PageSection::get()->byID($sectionId);
            if (!$section) {
                throw new ToolException("No PageSection found with id $sectionId");
            }
            if (!$this->canViewSection($section, $actingMember)) {
                throw new ToolException('Permission denied: cannot view this PageSection');
            }
        } else {
            if (!isset($arguments['parentId']) || !is_numeric($arguments['parentId'])) {
                throw new ToolException(
                    'Either "sectionId" or "parentId" (with optional "parentClass" and "sectionName") is required'
                );
            }
            // Mirror resolveSections() for a single parent, but fail hard on a
            // missing section (a reorder needs an existing container).
            $parentClass = $arguments['parentClass'] ?? SiteTree::class;
            $sectionName = $arguments['sectionName'] ?? 'Main';
            $targets = $this->resolveSections([
                'elementId' => 0, // unused: section is never created
                'sectionId' => null,
                'parentIds' => [(int) $arguments['parentId']],
                'parentClass' => $parentClass,
                'sectionName' => $sectionName,
            ], false, $actingMember);
            $section = $targets[0]['section'];
            if (!$section) {
                $parentObject = $targets[0]['parent'];
                throw new ToolException(
                    "No section \"{$sectionName}\" found on {$parentClass} #{$parentObject->ID}"
                );
            }
            $extra = [
                'parentId' => (int) $arguments['parentId'],
                'parentClass' => $parentClass,
                'sectionName' => $sectionName,
            ];
            $sectionOwner = $targets[0]['parent'];
        }

        if (!$this->canEditSection($section, $sectionOwner, $actingMember)) {
            throw new ToolException('Permission denied: cannot edit PageSection #' . $section->ID);
        }

        return $this->jsonResult(
            $this->reorder($section->Elements(), $desired) + [
                'sectionId' => $section->ID,
            ] + $extra
        );
    }

    /**
     * Parse and validate the desired order.
     *
     * @return int[]
     */
    private function parseDesiredOrder($raw): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new ToolException('"elementIds" must be a non-empty array of element IDs');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_numeric($id) || (int) $id <= 0) {
                throw new ToolException('"elementIds" must contain only positive integers');
            }
            $id = (int) $id;
            if (in_array($id, $ids, true)) {
                throw new ToolException("Duplicate ID $id in \"elementIds\"");
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /**
     * Validate the desired order against the container's current members and
     * apply it. All-or-nothing: nothing is written until the list is fully
     * validated (every current member present exactly once, no unknown IDs).
     */
    private function reorder(RelationList $list, array $desired): array
    {
        $current = $list->Sort('SortOrder')->Column('ID');
        if (count($current) === 0) {
            throw new ToolException('The container has no elements to order');
        }

        $missing = array_values(array_diff($current, $desired));
        $extra = array_values(array_diff($desired, $current));
        if ($missing) {
            throw new ToolException(
                'Incomplete order: current members missing from "elementIds": ' . implode(', ', $missing)
            );
        }
        if ($extra) {
            throw new ToolException(
                'Unknown members in "elementIds" (not part of this container): ' . implode(', ', $extra)
            );
        }

        if ($desired === $current) {
            return [
                'action' => 'order_unchanged',
                'order' => $current,
                'changedCount' => 0,
            ];
        }

        // Canonical reorder, mirroring PageSection::onBeforeWrite() /
        // PageElement::onBeforeWrite(): update every join row in place with the
        // new SortOrder and the "__NewOrder" flag so the relation's
        // counter/version logic is skipped. All rows are re-sequenced (not just
        // the moved ones) because stored SortOrder values are not guaranteed
        // to be contiguous ranks.
        $count = count($desired);
        for ($i = 0; $i < $count; $i++) {
            $list->add($desired[$i], ['SortOrder' => ($i + 1) * 2, '__NewOrder' => true]);
        }

        return [
            'action' => 'reordered',
            'order' => $desired,
            'changedCount' => $count,
        ];
    }
}
