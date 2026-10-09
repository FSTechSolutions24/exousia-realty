<script setup lang="ts">
import { BlockQuote, Bold, ClassicEditor, Essentials, Heading, Italic, Link, List, Paragraph, Underline, type EditorConfig } from 'ckeditor5'
import { Ckeditor } from '@ckeditor/ckeditor5-vue'

const model = defineModel<string>({ default: '' })
defineProps<{ disabled?: boolean }>()

const editor = ClassicEditor
const config: EditorConfig = {
  licenseKey: 'GPL',
  plugins: [Essentials, Paragraph, Heading, Bold, Italic, Underline, Link, List, BlockQuote],
  toolbar: ['undo', 'redo', '|', 'heading', '|', 'bold', 'italic', 'underline', '|', 'link', 'bulletedList', 'numberedList', 'blockQuote'],
  heading: { options: [
    { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
    { model: 'heading2', view: 'h2', title: 'Heading', class: 'ck-heading_heading2' },
    { model: 'heading3', view: 'h3', title: 'Subheading', class: 'ck-heading_heading3' },
  ] },
}
</script>

<template>
  <Ckeditor v-model="model" :editor="editor" :config="config" :disabled="disabled" />
</template>
