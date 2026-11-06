<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Underline from '@tiptap/extension-underline'
import Placeholder from '@tiptap/extension-placeholder'
import TextAlign from '@tiptap/extension-text-align'

const props = withDefaults(
  defineProps<{
    modelValue: string
    disabled?: boolean
    placeholder?: string
  }>(),
  {
    disabled: false,
    placeholder: 'Write your message…',
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const editor = useEditor({
  content: props.modelValue || '',
  editable: !props.disabled,
  extensions: [
    StarterKit.configure({
      heading: { levels: [1, 2, 3] },
    }),
    Underline,
    Link.configure({
      openOnClick: false,
      HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
    }),
    TextAlign.configure({
      types: ['heading', 'paragraph'],
    }),
    Placeholder.configure({
      placeholder: props.placeholder,
    }),
  ],
  editorProps: {
    attributes: {
      class: 'html-editor__content',
    },
  },
  onUpdate: ({ editor: current }) => {
    emit('update:modelValue', current.getHTML())
  },
})

watch(
  () => props.modelValue,
  (value) => {
    if (!editor.value) return
    const current = editor.value.getHTML()
    if (value !== current) {
      editor.value.commands.setContent(value || '', { emitUpdate: false })
    }
  },
)

watch(
  () => props.disabled,
  (disabled) => {
    editor.value?.setEditable(!disabled)
  },
)

onBeforeUnmount(() => {
  editor.value?.destroy()
})

function run(command: () => boolean) {
  if (!editor.value || props.disabled) return
  command()
  editor.value.chain().focus().run()
}

function setLink() {
  if (!editor.value || props.disabled) return
  const previous = editor.value.getAttributes('link').href as string | undefined
  const url = window.prompt('Link URL', previous || 'https://')
  if (url === null) return
  if (url.trim() === '') {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run()
    return
  }
  editor.value.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run()
}
</script>

<template>
  <div class="html-editor" :class="{ 'html-editor--disabled': disabled }">
    <div v-if="editor" class="html-editor__toolbar" role="toolbar" aria-label="Formatting">
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-bold"
        :disabled="disabled"
        :color="editor.isActive('bold') ? 'primary' : undefined"
        aria-label="Bold"
        @click="run(() => editor!.chain().focus().toggleBold().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-italic"
        :disabled="disabled"
        :color="editor.isActive('italic') ? 'primary' : undefined"
        aria-label="Italic"
        @click="run(() => editor!.chain().focus().toggleItalic().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-underline"
        :disabled="disabled"
        :color="editor.isActive('underline') ? 'primary' : undefined"
        aria-label="Underline"
        @click="run(() => editor!.chain().focus().toggleUnderline().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-strikethrough"
        :disabled="disabled"
        :color="editor.isActive('strike') ? 'primary' : undefined"
        aria-label="Strikethrough"
        @click="run(() => editor!.chain().focus().toggleStrike().run())"
      />
      <v-divider vertical class="mx-1" />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-header-1"
        :disabled="disabled"
        :color="editor.isActive('heading', { level: 1 }) ? 'primary' : undefined"
        aria-label="Heading 1"
        @click="run(() => editor!.chain().focus().toggleHeading({ level: 1 }).run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-header-2"
        :disabled="disabled"
        :color="editor.isActive('heading', { level: 2 }) ? 'primary' : undefined"
        aria-label="Heading 2"
        @click="run(() => editor!.chain().focus().toggleHeading({ level: 2 }).run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-header-3"
        :disabled="disabled"
        :color="editor.isActive('heading', { level: 3 }) ? 'primary' : undefined"
        aria-label="Heading 3"
        @click="run(() => editor!.chain().focus().toggleHeading({ level: 3 }).run())"
      />
      <v-divider vertical class="mx-1" />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-list-bulleted"
        :disabled="disabled"
        :color="editor.isActive('bulletList') ? 'primary' : undefined"
        aria-label="Bullet list"
        @click="run(() => editor!.chain().focus().toggleBulletList().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-list-numbered"
        :disabled="disabled"
        :color="editor.isActive('orderedList') ? 'primary' : undefined"
        aria-label="Numbered list"
        @click="run(() => editor!.chain().focus().toggleOrderedList().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-quote-close"
        :disabled="disabled"
        :color="editor.isActive('blockquote') ? 'primary' : undefined"
        aria-label="Quote"
        @click="run(() => editor!.chain().focus().toggleBlockquote().run())"
      />
      <v-divider vertical class="mx-1" />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-align-left"
        :disabled="disabled"
        :color="editor.isActive({ textAlign: 'left' }) ? 'primary' : undefined"
        aria-label="Align left"
        @click="run(() => editor!.chain().focus().setTextAlign('left').run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-align-center"
        :disabled="disabled"
        :color="editor.isActive({ textAlign: 'center' }) ? 'primary' : undefined"
        aria-label="Align center"
        @click="run(() => editor!.chain().focus().setTextAlign('center').run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-format-align-right"
        :disabled="disabled"
        :color="editor.isActive({ textAlign: 'right' }) ? 'primary' : undefined"
        aria-label="Align right"
        @click="run(() => editor!.chain().focus().setTextAlign('right').run())"
      />
      <v-divider vertical class="mx-1" />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-link-variant"
        :disabled="disabled"
        :color="editor.isActive('link') ? 'primary' : undefined"
        aria-label="Insert link"
        @click="setLink"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-link-variant-off"
        :disabled="disabled || !editor.isActive('link')"
        aria-label="Remove link"
        @click="run(() => editor!.chain().focus().unsetLink().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-code-tags"
        :disabled="disabled"
        :color="editor.isActive('code') ? 'primary' : undefined"
        aria-label="Inline code"
        @click="run(() => editor!.chain().focus().toggleCode().run())"
      />
      <v-spacer />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-undo"
        :disabled="disabled || !editor.can().undo()"
        aria-label="Undo"
        @click="run(() => editor!.chain().focus().undo().run())"
      />
      <v-btn
        size="small"
        variant="text"
        icon="mdi-redo"
        :disabled="disabled || !editor.can().redo()"
        aria-label="Redo"
        @click="run(() => editor!.chain().focus().redo().run())"
      />
    </div>
    <EditorContent :editor="editor" class="html-editor__surface" />
  </div>
</template>

<style scoped>
.html-editor {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
}

.html-editor--disabled {
  opacity: 0.65;
  pointer-events: none;
}

.html-editor__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.1rem;
  padding: 0.25rem 0.35rem;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgba(var(--v-theme-on-surface), 0.03);
}

.html-editor__surface {
  min-height: 220px;
}

.html-editor__surface :deep(.tiptap),
.html-editor__surface :deep(.ProseMirror) {
  min-height: 220px;
  padding: 0.85rem 1rem;
  outline: none;
  font-size: 0.95rem;
  line-height: 1.55;
}

.html-editor__surface :deep(.tiptap p.is-editor-empty:first-child::before),
.html-editor__surface :deep(.ProseMirror p.is-editor-empty:first-child::before) {
  color: rgba(var(--v-theme-on-surface), 0.38);
  content: attr(data-placeholder);
  float: left;
  height: 0;
  pointer-events: none;
}

.html-editor__surface :deep(.tiptap p),
.html-editor__surface :deep(.ProseMirror p) {
  margin: 0 0 0.65em;
}

.html-editor__surface :deep(.tiptap h1),
.html-editor__surface :deep(.ProseMirror h1),
.html-editor__surface :deep(.tiptap h2),
.html-editor__surface :deep(.ProseMirror h2),
.html-editor__surface :deep(.tiptap h3),
.html-editor__surface :deep(.ProseMirror h3) {
  line-height: 1.25;
  margin: 0.4em 0 0.5em;
}

.html-editor__surface :deep(.tiptap ul),
.html-editor__surface :deep(.ProseMirror ul),
.html-editor__surface :deep(.tiptap ol),
.html-editor__surface :deep(.ProseMirror ol) {
  padding-left: 1.4rem;
  margin: 0 0 0.65em;
}

.html-editor__surface :deep(.tiptap blockquote),
.html-editor__surface :deep(.ProseMirror blockquote) {
  border-left: 3px solid rgba(var(--v-theme-primary), 0.45);
  margin: 0 0 0.65em;
  padding-left: 0.85rem;
  color: rgba(var(--v-theme-on-surface), 0.75);
}

.html-editor__surface :deep(.tiptap a),
.html-editor__surface :deep(.ProseMirror a) {
  color: rgb(var(--v-theme-primary));
  text-decoration: underline;
}

.html-editor__surface :deep(.tiptap code),
.html-editor__surface :deep(.ProseMirror code) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.9em;
  background: rgba(var(--v-theme-on-surface), 0.06);
  padding: 0.1em 0.3em;
  border-radius: 4px;
}
</style>
