package com.yasirarafat.clipnotes.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.data.Category
import com.yasirarafat.clipnotes.ui.NotesViewModel
import com.yasirarafat.clipnotes.ui.theme.NoteStripColors

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EditNoteScreen(
    vm: NotesViewModel,
    noteId: Long,
    categories: List<Category>,
    initialContent: String? = null,
    onDone: () -> Unit
) {
    var title by remember { mutableStateOf("") }
    var content by remember { mutableStateOf(if (noteId == 0L) (initialContent ?: "") else "") }
    var categoryId by remember { mutableStateOf<Long?>(null) }
    var colorIndex by remember { mutableStateOf(0) }

    LaunchedEffect(noteId) {
        if (noteId != 0L) {
            val note = vm.loadNote(noteId)
            if (note != null) {
                title = note.title
                content = note.content
                categoryId = note.categoryId
                colorIndex = note.color
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(if (noteId == 0L) "New Note" else "Edit Note") },
                navigationIcon = {
                    IconButton(onClick = onDone) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    TextButton(
                        onClick = {
                            vm.saveNote(noteId, title.trim(), content.trim(), categoryId, colorIndex)
                            onDone()
                        },
                        enabled = title.isNotBlank() || content.isNotBlank()
                    ) {
                        Text("SAVE", color = Color.White, fontWeight = FontWeight.Bold)
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = androidx.compose.material3.MaterialTheme.colorScheme.primary,
                    titleContentColor = Color.White,
                    navigationIconContentColor = Color.White,
                    actionIconContentColor = Color.White
                )
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier
                .padding(padding)
                .fillMaxSize()
                .padding(16.dp)
        ) {
            OutlinedTextField(
                value = title,
                onValueChange = { title = it },
                label = { Text("Title (optional)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(Modifier.size(12.dp))
            OutlinedTextField(
                value = content,
                onValueChange = { content = it },
                label = { Text("Text to save & copy") },
                modifier = Modifier
                    .fillMaxWidth()
                    .weight(1f)
            )
            if (categories.isNotEmpty()) {
                Spacer(Modifier.size(12.dp))
                Text("Category", style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
                Spacer(Modifier.size(6.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item {
                        FilterChip(
                            selected = categoryId == null,
                            onClick = { categoryId = null },
                            label = { Text("None") }
                        )
                    }
                    items(categories, key = { it.id }) { cat ->
                        FilterChip(
                            selected = categoryId == cat.id,
                            onClick = { categoryId = cat.id },
                            label = { Text(cat.name) }
                        )
                    }
                }
            }

            Spacer(Modifier.size(12.dp))
            Text("Note color", style = MaterialTheme.typography.labelLarge)
            Spacer(Modifier.size(6.dp))
            Row {
                NoteStripColors.forEachIndexed { index, c ->
                    val selected = colorIndex == index
                    Box(modifier = Modifier.size(38.dp).padding(end = 10.dp)) {
                        Box(
                            modifier = Modifier
                                .size(30.dp)
                                .clip(CircleShape)
                                .background(if (index == 0) MaterialTheme.colorScheme.surfaceVariant else c)
                                .border(
                                    width = if (selected) 3.dp else 1.dp,
                                    color = if (selected) MaterialTheme.colorScheme.onSurface
                                    else MaterialTheme.colorScheme.outline,
                                    shape = CircleShape
                                )
                                .clickable { colorIndex = index }
                        )
                    }
                }
            }
        }
    }
}
