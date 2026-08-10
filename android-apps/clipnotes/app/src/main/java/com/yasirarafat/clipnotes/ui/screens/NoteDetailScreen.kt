package com.yasirarafat.clipnotes.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.DragIndicator
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Folder
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.PushPin
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AssistChip
import androidx.compose.material3.AssistChipDefaults
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.data.Checklist
import com.yasirarafat.clipnotes.data.ChecklistItem
import com.yasirarafat.clipnotes.data.Note
import com.yasirarafat.clipnotes.ui.theme.NoteStripColors
import java.text.SimpleDateFormat
import java.util.Locale

/**
 * Read-only view of a single note. Opens when the user taps a note card, so the
 * full content is visible without going into the editor. Copy / Edit / Share /
 * Favorite / Pin / Lock / Trash are all available from here.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NoteDetailScreen(
    note: Note,
    categoryName: String?,
    onBack: () -> Unit,
    onEdit: () -> Unit,
    onCopy: () -> Unit,
    onShare: () -> Unit,
    onToggleFavorite: () -> Unit,
    onTogglePin: () -> Unit,
    onTrash: () -> Unit,
    onToggleLock: () -> Unit,
    onToggleChecklistItem: (Int) -> Unit,
    onReorderChecklist: (List<ChecklistItem>) -> Unit = {}
) {
    var menuOpen by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Note") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    IconButton(onClick = onToggleFavorite) {
                        Icon(
                            if (note.isFavorite) Icons.Filled.Star else Icons.Filled.StarBorder,
                            contentDescription = "Favorite"
                        )
                    }
                    IconButton(onClick = onEdit) {
                        Icon(Icons.Filled.Edit, contentDescription = "Edit")
                    }
                    Box {
                        IconButton(onClick = { menuOpen = true }) {
                            Icon(Icons.Filled.Share, contentDescription = "More")
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
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = MaterialTheme.colorScheme.primary,
                    titleContentColor = Color.White,
                    navigationIconContentColor = Color.White,
                    actionIconContentColor = Color.White
                )
            )
        },
        bottomBar = {
            // navigationBarsPadding keeps the Copy button above the system nav
            // bar (targetSdk 36 draws edge-to-edge, so content sits behind it).
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .navigationBarsPadding()
                    .padding(16.dp)
            ) {
                Button(onClick = onCopy, modifier = Modifier.fillMaxWidth()) {
                    Icon(Icons.Filled.ContentCopy, null, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(8.dp))
                    Text("Copy")
                }
            }
        }
    ) { padding ->
        if (note.isChecklist) {
            // Draggable checklist: long-press a row and drag to reorder.
            val working = remember(note.id) {
                mutableStateListOf<ChecklistItem>().apply { addAll(Checklist.parse(note.content)) }
            }
            val listState = rememberLazyListState()
            val dragState = rememberDragDropState(
                listState = listState,
                onMove = { from, to -> working.add(to, working.removeAt(from)) },
                onDrop = { onReorderChecklist(working.toList()) }
            )
            // Re-sync from the note whenever its content changes and we're not dragging.
            LaunchedEffect(note.content) {
                if (dragState.draggingItemIndex == null) {
                    working.clear()
                    working.addAll(Checklist.parse(note.content))
                }
            }
            Column(
                modifier = Modifier
                    .padding(padding)
                    .fillMaxSize()
                    .padding(16.dp)
            ) {
                DetailHeader(note, categoryName)
                Spacer(Modifier.size(8.dp))
                if (working.isEmpty()) {
                    Text("(empty)", color = MaterialTheme.colorScheme.onSurfaceVariant)
                } else {
                    Text(
                        "Long-press an item and drag to reorder.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                    Spacer(Modifier.size(4.dp))
                    LazyColumn(
                        state = listState,
                        modifier = Modifier
                            .weight(1f)
                            .fillMaxWidth()
                            .dragContainer(dragState)
                    ) {
                        itemsIndexed(working) { index, item ->
                            DraggableItem(dragState, index) { dragging ->
                                Row(
                                    verticalAlignment = Alignment.CenterVertically,
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clip(RoundedCornerShape(8.dp))
                                        .background(
                                            if (dragging) MaterialTheme.colorScheme.surfaceVariant
                                            else Color.Transparent
                                        )
                                        .padding(vertical = 2.dp)
                                ) {
                                    Icon(
                                        Icons.Filled.DragIndicator,
                                        contentDescription = "Drag",
                                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                        modifier = Modifier.size(20.dp)
                                    )
                                    Checkbox(
                                        checked = item.checked,
                                        onCheckedChange = { onToggleChecklistItem(index) }
                                    )
                                    Text(
                                        text = item.text,
                                        style = MaterialTheme.typography.bodyLarge,
                                        textDecoration = if (item.checked) TextDecoration.LineThrough else null,
                                        color = if (item.checked) MaterialTheme.colorScheme.onSurfaceVariant
                                        else MaterialTheme.colorScheme.onSurface
                                    )
                                }
                            }
                        }
                    }
                }
                CopyCountLabel(note)
            }
        } else {
            Column(
                modifier = Modifier
                    .padding(padding)
                    .fillMaxSize()
                    .verticalScroll(rememberScrollState())
                    .padding(16.dp)
            ) {
                DetailHeader(note, categoryName)
                Spacer(Modifier.size(16.dp))
                SelectionContainer {
                    Text(
                        text = note.content.ifBlank { "(empty)" },
                        style = MaterialTheme.typography.bodyLarge,
                        color = if (note.content.isBlank()) MaterialTheme.colorScheme.onSurfaceVariant
                        else MaterialTheme.colorScheme.onSurface
                    )
                }
                CopyCountLabel(note)
            }
        }
    }
}

/** Category / reminder chips + the note title, shown at the top of the detail view. */
@Composable
private fun DetailHeader(note: Note, categoryName: String?) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        if (categoryName != null) {
            AssistChip(
                onClick = { },
                label = { Text(categoryName) },
                leadingIcon = { Icon(Icons.Filled.Folder, null, modifier = Modifier.size(16.dp)) },
                colors = AssistChipDefaults.assistChipColors()
            )
        }
        note.reminderAt?.let { at ->
            AssistChip(
                onClick = { },
                label = { Text(SimpleDateFormat("MMM d • h:mm a", Locale.getDefault()).format(at)) },
                leadingIcon = { Icon(Icons.Filled.Notifications, null, modifier = Modifier.size(16.dp)) },
                colors = AssistChipDefaults.assistChipColors()
            )
        }
    }
    if (note.title.isNotBlank()) {
        Spacer(Modifier.size(12.dp))
        Text(
            note.title,
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.onSurface
        )
    }
}

/** "Copied N×" footer, if the note has ever been copied. */
@Composable
private fun CopyCountLabel(note: Note) {
    if (note.copyCount > 0) {
        Spacer(Modifier.size(12.dp))
        Text(
            "Copied ${note.copyCount}×",
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}
