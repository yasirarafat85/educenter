package com.yasirarafat.clipnotes.data

/** A single checklist row. */
data class ChecklistItem(val text: String, val checked: Boolean)

/**
 * Checklist notes store their items in the note's [Note.content], one item per line.
 * A checked item is prefixed with "[x] "; an unchecked item is stored as plain text.
 */
object Checklist {

    fun parse(content: String): List<ChecklistItem> =
        content.split("\n")
            .map { it.trimEnd() }
            .filter { it.isNotBlank() }
            .map { line ->
                when {
                    line.startsWith("[x] ") -> ChecklistItem(line.removePrefix("[x] "), true)
                    line.startsWith("[X] ") -> ChecklistItem(line.removePrefix("[X] "), true)
                    line.startsWith("[ ] ") -> ChecklistItem(line.removePrefix("[ ] "), false)
                    else -> ChecklistItem(line, false)
                }
            }

    private fun serialize(items: List<ChecklistItem>): String =
        items.joinToString("\n") { if (it.checked) "[x] ${it.text}" else it.text }

    /** Build note content from a list of items (used after a drag-reorder). */
    fun build(items: List<ChecklistItem>): String = serialize(items)

    /** Flip the checked state of the item at [index], returning the new content. */
    fun toggleAt(content: String, index: Int): String {
        val items = parse(content).toMutableList()
        if (index !in items.indices) return content
        val item = items[index]
        items[index] = item.copy(checked = !item.checked)
        return serialize(items)
    }

    /** Move the item at [from] to [to] (drag-reorder), returning the new content. */
    fun move(content: String, from: Int, to: Int): String {
        val items = parse(content).toMutableList()
        if (from !in items.indices || to !in items.indices || from == to) return content
        items.add(to, items.removeAt(from))
        return serialize(items)
    }

    /** Plain text (no markers) — used when copying a checklist note. */
    fun plainText(content: String): String =
        parse(content).joinToString("\n") { it.text }
}
