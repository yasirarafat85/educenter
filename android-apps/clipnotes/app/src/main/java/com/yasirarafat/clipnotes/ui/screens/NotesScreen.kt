package com.yasirarafat.clipnotes.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Folder
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.LockOpen
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.PushPin
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.data.Category
import com.yasirarafat.clipnotes.data.Checklist
import com.yasirarafat.clipnotes.data.Note
import com.yasirarafat.clipnotes.ui.theme.NoteStripColors

@Composable
fun NotesList(
    notes: List<Note>,
    categories: List<Category>,
    query: String,
    sortMode: Int,
    emptyText: String,
    onCopy: (Note) -> Unit,
    onOpenDetail: (Note) -> Unit,
    onEdit: (Note) -> Unit,
    onToggleFavorite: (Note) -> Unit,
    onTogglePin: (Note) -> Unit,
    onShare: (Note) -> Unit,
    onTrash: (Note) -> Unit,
    onToggleChecklistItem: (Note, Int) -> Unit,
    revealedIds: Set<Long>,
    onRequestUnlock: (Note) -> Unit,
    onRelock: (Note) -> Unit,
    onToggleLock: (Note) -> Unit,
    allowReorder: Boolean = false,
    onReorder: (List<Note>) -> Unit = {}
) {
    val catMap = remember(categories) { categories.associateBy({ it.id }, { it.name }) }

    // One place that renders a card, reused by the normal and drag-order lists.
    @Composable
    fun card(note: Note) {
        NoteCard(
            note = note,
            categoryName = note.categoryId?.let { catMap[it] },
            onCopy = { onCopy(note) },
            onOpenDetail = { onOpenDetail(note) },
            onEdit = { onEdit(note) },
            onToggleFavorite = { onToggleFavorite(note) },
            onTogglePin = { onTogglePin(note) },
            onShare = { onShare(note) },
            onTrash = { onTrash(note) },
            onToggleItem = { index -> onToggleChecklistItem(note, index) },
            revealed = note.id in revealedIds,
            onRequestUnlock = { onRequestUnlock(note) },
            onRelock = { onRelock(note) },
            onToggleLock = { onToggleLock(note) }
        )
    }

    // Manual (drag) order — only on the full notes list, not while searching
    // or viewing a filtered subset (favorites / a single category).
    val isManual = allowReorder && sortMode == 3 && query.isBlank()
    if (isManual) {
        val working = remember { mutableStateListOf<Note>().apply { addAll(notes.sortedBy { it.position }) } }
        val listState = rememberLazyListState()
        val dragState = rememberDragDropState(
            listState = listState,
            onMove = { from, to -> working.add(to, working.removeAt(from)) },
            onDrop = { onReorder(working.toList()) }
        )
        // Re-sync from the database whenever notes change and we're not dragging.
        LaunchedEffect(notes) {
            if (dragState.draggingItemIndex == null) {
                working.clear()
                working.addAll(notes.sortedBy { it.position })
            }
        }
        if (working.isEmpty()) {
            EmptyState(emptyText)
        } else {
            LazyColumn(
                state = listState,
                modifier = Modifier.fillMaxSize().dragContainer(dragState),
                contentPadding = PaddingValues(12.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp)
            ) {
                itemsIndexed(working, key = { _, n -> n.id }) { index, note ->
                    DraggableItem(dragState, index) { _ -> card(note) }
                }
            }
        }
        return
    }

    val filtered = remember(notes, query, sortMode) {
        val base = if (query.isBlank()) notes
        else notes.filter {
            it.title.contains(query, ignoreCase = true) ||
                it.content.contains(query, ignoreCase = true)
        }
        // Pinned notes always first, then by the chosen sort mode.
        base.sortedWith(
            compareByDescending<Note> { it.isPinned }.thenComparator { a, b ->
                when (sortMode) {
                    1 -> (a.title.ifBlank { a.content }).compareTo(b.title.ifBlank { b.content }, ignoreCase = true)
                    2 -> b.copyCount.compareTo(a.copyCount)
                    else -> b.updatedAt.compareTo(a.updatedAt)
                }
            }
        )
    }

    if (filtered.isEmpty()) {
        EmptyState(emptyText)
    } else {
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(12.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp)
        ) {
            items(filtered, key = { it.id }) { note ->
                card(note)
            }
        }
    }
}

@Composable
private fun NoteCard(
    note: Note,
    categoryName: String?,
    onCopy: () -> Unit,
    onOpenDetail: () -> Unit,
    onEdit: () -> Unit,
    onToggleFavorite: () -> Unit,
    onTogglePin: () -> Unit,
    onShare: () -> Unit,
    onTrash: () -> Unit,
    onToggleItem: (Int) -> Unit,
    revealed: Boolean,
    onRequestUnlock: () -> Unit,
    onRelock: () -> Unit,
    onToggleLock: () -> Unit
) {
    var menuOpen by remember { mutableStateOf(false) }
    val hidden = note.isLocked && !revealed

    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable { if (hidden) onRequestUnlock() else onOpenDetail() },
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
    ) {
      if (hidden) {
          Row(modifier = Modifier.height(IntrinsicSize.Min)) {
              if (note.color in 1 until NoteStripColors.size) {
                  Box(
                      modifier = Modifier
                          .width(6.dp)
                          .fillMaxHeight()
                          .background(NoteStripColors[note.color])
                  )
              }
              Row(
                  modifier = Modifier.padding(16.dp).fillMaxWidth(),
                  verticalAlignment = Alignment.CenterVertically
              ) {
                  Icon(Icons.Filled.Lock, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                  Spacer(Modifier.width(12.dp))
                  Column(modifier = Modifier.weight(1f)) {
                      Text(
                          text = note.title.ifBlank { if (note.isChecklist) "Checklist" else "Note" },
                          style = MaterialTheme.typography.titleMedium,
                          fontWeight = FontWeight.SemiBold,
                          maxLines = 1,
                          overflow = TextOverflow.Ellipsis,
                          color = MaterialTheme.colorScheme.onSurface
                      )
                      Text(
                          "Locked — tap to unlock",
                          style = MaterialTheme.typography.bodySmall,
                          color = MaterialTheme.colorScheme.onSurfaceVariant
                      )
                  }
              }
          }
      } else {
      Row(modifier = Modifier.height(IntrinsicSize.Min)) {
        if (note.color in 1 until NoteStripColors.size) {
            Box(
                modifier = Modifier
                    .width(6.dp)
                    .fillMaxHeight()
                    .background(NoteStripColors[note.color])
            )
        }
        Column(modifier = Modifier.padding(start = 16.dp, end = 8.dp, top = 12.dp, bottom = 8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(modifier = Modifier.weight(1f)) {
                    if (note.title.isNotBlank()) {
                        Text(
                            text = note.title,
                            style = MaterialTheme.typography.titleMedium,
                            fontWeight = FontWeight.SemiBold,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                            color = MaterialTheme.colorScheme.onSurface
                        )
                        Spacer(Modifier.size(4.dp))
                    }
                    if (note.isChecklist) {
                        val items = remember(note.content) { Checklist.parse(note.content) }
                        Column {
                            items.take(8).forEachIndexed { index, item ->
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Checkbox(
                                        checked = item.checked,
                                        onCheckedChange = { onToggleItem(index) },
                                        modifier = Modifier.size(28.dp)
                                    )
                                    Spacer(Modifier.width(4.dp))
                                    Text(
                                        text = item.text,
                                        style = MaterialTheme.typography.bodyMedium,
                                        maxLines = 2,
                                        overflow = TextOverflow.Ellipsis,
                                        textDecoration = if (item.checked) TextDecoration.LineThrough else null,
                                        color = if (item.checked) MaterialTheme.colorScheme.onSurfaceVariant
                                        else MaterialTheme.colorScheme.onSurface
                                    )
                                }
                            }
                            if (items.size > 8) {
                                Text(
                                    "+${items.size - 8} more",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant
                                )
                            }
                        }
                    } else {
                        Text(
                            text = note.content.ifBlank { "(empty)" },
                            style = MaterialTheme.typography.bodyMedium,
                            maxLines = 6,
                            overflow = TextOverflow.Ellipsis,
                            color = MaterialTheme.colorScheme.onSurfaceVariant
                        )
                    }
                }
                if (note.isPinned) {
                    Icon(
                        Icons.Filled.PushPin,
                        contentDescription = "Pinned",
                        tint = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.size(18.dp)
                    )
                    Spacer(Modifier.size(4.dp))
                }
                if (note.isLocked) {
                    IconButton(onClick = onRelock) {
                        Icon(
                            Icons.Filled.LockOpen,
                            contentDescription = "Lock now",
                            tint = MaterialTheme.colorScheme.primary
                        )
                    }
                }
                IconButton(onClick = onToggleFavorite) {
                    Icon(
                        imageVector = if (note.isFavorite) Icons.Filled.Star else Icons.Filled.StarBorder,
                        contentDescription = "Favorite",
                        tint = if (note.isFavorite) MaterialTheme.colorScheme.primary
                        else MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                Box {
                    IconButton(onClick = { menuOpen = true }) {
                        Icon(Icons.Filled.MoreVert, contentDescription = "More")
                    }
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                        DropdownMenuItem(
                            text = { Text(if (note.isPinned) "Unpin" else "Pin to top") },
                            leadingIcon = { Icon(Icons.Filled.PushPin, null) },
                            onClick = { menuOpen = false; onTogglePin() }
                        )
                        DropdownMenuItem(
                            text = { Text(if (note.isLocked) "Unlock note" else "Lock note") },
                            leadingIcon = { Icon(Icons.Filled.Lock, null) },
                            onClick = { menuOpen = false; onToggleLock() }
                        )
                        DropdownMenuItem(
                            text = { Text("Edit") },
                            leadingIcon = { Icon(Icons.Filled.Edit, null) },
                            onClick = { menuOpen = false; onEdit() }
                        )
                        DropdownMenuItem(
                            text = { Text("Share") },
                            leadingIcon = { Icon(Icons.Filled.Share, null) },
                            onClick = { menuOpen = false; onShare() }
                        )
                        DropdownMenuItem(
                            text = { Text("Move to trash") },
                            leadingIcon = { Icon(Icons.Filled.Delete, null) },
                            onClick = { menuOpen = false; onTrash() }
                        )
                    }
                }
            }

            Row(
                modifier = Modifier.fillMaxWidth().padding(top = 4.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                if (categoryName != null) {
                    AssistChip(
                        onClick = { },
                        label = { Text(categoryName) },
                        leadingIcon = {
                            Icon(Icons.Filled.Folder, null, modifier = Modifier.size(16.dp))
                        },
                        colors = AssistChipDefaults.assistChipColors()
                    )
                }
                Spacer(Modifier.weight(1f))
                if (note.copyCount > 0) {
                    Text(
                        "copied ${note.copyCount}×",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                    Spacer(Modifier.width(8.dp))
                }
                TextButton(onClick = onCopy) {
                    Icon(Icons.Filled.ContentCopy, null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text("Copy")
                }
            }
        }
      }
      }
    }
}

@Composable
fun EmptyState(text: String) {
    Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(
                Icons.Filled.Inbox,
                contentDescription = null,
                modifier = Modifier.size(72.dp),
                tint = MaterialTheme.colorScheme.outline
            )
            Spacer(Modifier.size(12.dp))
            Text(
                text = text,
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(horizontal = 32.dp),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center
            )
        }
    }
}
