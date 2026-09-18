import { cn } from '@/lib/utils';
import Placeholder from '@tiptap/extension-placeholder';
import { EditorContent, useEditor, type JSONContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Code, Italic, List, ListOrdered, Quote } from 'lucide-react';
import { useEffect } from 'react';

// Link ships inside StarterKit in tiptap 3 — configure it there rather than
// registering the extension twice.
const extensions = [
    StarterKit.configure({
        heading: { levels: [2, 3] },
        link: { openOnClick: false, autolink: true },
    }),
];

const prose =
    'prose-sm max-w-none text-ink [&_p]:my-1.5 [&_ul]:my-1.5 [&_ol]:my-1.5 ' +
    '[&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 ' +
    '[&_h2]:mt-3 [&_h2]:text-base [&_h2]:font-semibold ' +
    '[&_h3]:mt-3 [&_h3]:text-sm [&_h3]:font-semibold ' +
    '[&_a]:text-accent [&_a]:underline ' +
    '[&_code]:rounded [&_code]:bg-surface [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[0.85em] ' +
    '[&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-surface [&_pre]:p-3 ' +
    '[&_blockquote]:border-l-2 [&_blockquote]:border-border-strong [&_blockquote]:pl-3 [&_blockquote]:text-ink-muted';

/** Read-only renderer for a stored tiptap document. */
export function RichTextView({ value }: { value: JSONContent | null }) {
    const editor = useEditor(
        { extensions, content: value, editable: false },
        [value],
    );

    if (!value) return null;

    return <EditorContent editor={editor} className={prose} />;
}

function ToolbarButton({
    active,
    onClick,
    label,
    icon: Icon,
}: {
    active: boolean;
    onClick: () => void;
    label: string;
    icon: typeof Bold;
}) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={active}
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={cn(
                'rounded p-1.5 transition',
                active
                    ? 'bg-accent-soft text-accent'
                    : 'text-ink-subtle hover:bg-surface hover:text-ink',
            )}
        >
            <Icon className="size-3.5" />
        </button>
    );
}

export function RichTextEditor({
    value,
    onChange,
    placeholder = 'Write something…',
    autoFocus = false,
    onSubmit,
}: {
    value: JSONContent | null;
    onChange: (value: JSONContent) => void;
    placeholder?: string;
    autoFocus?: boolean;
    onSubmit?: () => void;
}) {
    const editor = useEditor({
        extensions: [...extensions, Placeholder.configure({ placeholder })],
        content: value,
        autofocus: autoFocus,
        editorProps: {
            attributes: { class: cn(prose, 'min-h-[5rem] px-3 py-2 focus:outline-none') },
            handleKeyDown: (_view, event) => {
                // ⌘/Ctrl+Enter submits, matching the rest of the keyboard map.
                if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && onSubmit) {
                    event.preventDefault();
                    onSubmit();
                    return true;
                }
                return false;
            },
        },
        onUpdate: ({ editor }) => onChange(editor.getJSON()),
    });

    // Let the parent clear the editor after a successful submit.
    useEffect(() => {
        if (editor && value === null && !editor.isEmpty) {
            editor.commands.clearContent();
        }
    }, [editor, value]);

    if (!editor) return null;

    return (
        <div className="overflow-hidden rounded-lg border border-border-strong bg-raised focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/30">
            <div className="flex items-center gap-0.5 border-b border-border px-1.5 py-1">
                <ToolbarButton
                    label="Bold"
                    icon={Bold}
                    active={editor.isActive('bold')}
                    onClick={() => editor.chain().focus().toggleBold().run()}
                />
                <ToolbarButton
                    label="Italic"
                    icon={Italic}
                    active={editor.isActive('italic')}
                    onClick={() => editor.chain().focus().toggleItalic().run()}
                />
                <ToolbarButton
                    label="Code"
                    icon={Code}
                    active={editor.isActive('codeBlock')}
                    onClick={() => editor.chain().focus().toggleCodeBlock().run()}
                />
                <ToolbarButton
                    label="Bullet list"
                    icon={List}
                    active={editor.isActive('bulletList')}
                    onClick={() => editor.chain().focus().toggleBulletList().run()}
                />
                <ToolbarButton
                    label="Numbered list"
                    icon={ListOrdered}
                    active={editor.isActive('orderedList')}
                    onClick={() => editor.chain().focus().toggleOrderedList().run()}
                />
                <ToolbarButton
                    label="Quote"
                    icon={Quote}
                    active={editor.isActive('blockquote')}
                    onClick={() => editor.chain().focus().toggleBlockquote().run()}
                />
            </div>

            <EditorContent editor={editor} />
        </div>
    );
}
