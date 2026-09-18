<?php

namespace App\Support\RichText;

/**
 * Minimal reader for the tiptap/ProseMirror JSON the editor posts.
 *
 * Text is flattened server-side rather than trusted from the client: the flattened
 * copy feeds full-text search and notification emails, so it must actually match the
 * stored document.
 */
class TiptapDocument
{
    /** Node types that should produce a line break when flattened. */
    private const BLOCK_NODES = [
        'paragraph', 'heading', 'listItem', 'blockquote',
        'codeBlock', 'horizontalRule', 'tableRow',
    ];

    /** @param array<string, mixed>|null $document */
    public static function toPlainText(?array $document): string
    {
        if ($document === null) {
            return '';
        }

        $text = trim(self::walk($document));

        // Collapse the runs of blank lines that nested blocks leave behind.
        return (string) preg_replace("/\n{3,}/", "\n\n", $text);
    }

    /** @param array<string, mixed>|null $document */
    public static function isEmpty(?array $document): bool
    {
        return self::toPlainText($document) === '';
    }

    /**
     * Collect the ids in every mention node, so @-mentions can become watchers.
     *
     * @param  array<string, mixed>|null  $document
     * @return array<int, int>
     */
    public static function mentionedUserIds(?array $document): array
    {
        if ($document === null) {
            return [];
        }

        $ids = [];
        self::eachNode($document, function (array $node) use (&$ids) {
            if (($node['type'] ?? null) === 'mention') {
                $id = $node['attrs']['id'] ?? null;

                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        });

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $node */
    private static function walk(array $node): string
    {
        $type = $node['type'] ?? null;

        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }

        if ($type === 'mention') {
            return '@'.($node['attrs']['label'] ?? 'someone');
        }

        if ($type === 'hardBreak') {
            return "\n";
        }

        $children = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $children .= self::walk($child);
            }
        }

        return in_array($type, self::BLOCK_NODES, true) ? $children."\n" : $children;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): void  $callback
     */
    private static function eachNode(array $node, callable $callback): void
    {
        $callback($node);

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                self::eachNode($child, $callback);
            }
        }
    }
}
