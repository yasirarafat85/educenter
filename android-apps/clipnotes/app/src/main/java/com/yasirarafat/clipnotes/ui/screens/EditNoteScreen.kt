package com.yasirarafat.clipnotes.ui.screens

import android.Manifest
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.os.Build
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
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
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.data.Category
import com.yasirarafat.clipnotes.ui.NotesViewModel
import com.yasirarafat.clipnotes.ui.theme.NoteStripColors
import kotlinx.coroutines.delay
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EditNoteScreen(
    vm: NotesViewModel,
    noteId: Long,
    categories: List<Category>,
    initialContent: String? = null,
    initialCategoryId: Long? = null,
    onDone: () -> Unit
) {
    // rememberSaveable so edits survive rotation / the Activity being recreated.
    var title by rememberSaveable { mutableStateOf("") }
    var content by rememberSaveable { mutableStateOf(if (noteId == 0L) (initialContent ?: "") else "") }
    // A new note started inside a category is pre-assigned to it.
    var categoryId by rememberSaveable { mutableStateOf(if (noteId == 0L) initialCategoryId else null) }
    var colorIndex by rememberSaveable { mutableStateOf(0) }
    var isChecklist by rememberSaveable { mutableStateOf(false) }
    var reminderAt by rememberSaveable { mutableStateOf<Long?>(null) }
    var lockTimeoutSecs by rememberSaveable { mutableStateOf(0) }
    var lockOn by rememberSaveable { mutableStateOf(false) }
    // Loaded-once guard, unsaved-changes flag, and the exit warning dialog.
    var loaded by rememberSaveable { mutableStateOf(false) }
    var dirty by rememberSaveable { mutableStateOf(false) }
    var showExitDialog by rememberSaveable { mutableStateOf(false) }

    val context = LocalContext.current
    val notifPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* result ignored; the reminder is still set either way */ }

    fun pickReminder() {
        val cal = Calendar.getInstance()
        reminderAt?.let { cal.timeInMillis = it }
        DatePickerDialog(
            context,
            { _, year, month, day ->
                TimePickerDialog(
                    context,
                    { _, hour, minute ->
                        val c = Calendar.getInstance()
                        c.set(year, month, day, hour, minute, 0)
                        c.set(Calendar.MILLISECOND, 0)
                        reminderAt = c.timeInMillis; dirty = true
                    },
                    cal.get(Calendar.HOUR_OF_DAY), cal.get(Calendar.MINUTE), false
                ).show()
            },
            cal.get(Calendar.YEAR), cal.get(Calendar.MONTH), cal.get(Calendar.DAY_OF_MONTH)
        ).show()
    }

    // Load once: DB values (existing note) first, then any unsaved draft on top.
    LaunchedEffect(noteId) {
        if (loaded) return@LaunchedEffect
        if (noteId != 0L) {
            val note = vm.loadNote(noteId)
            if (note != null) {
                title = note.title
                content = note.content
                categoryId = note.categoryId
                colorIndex = note.color
                isChecklist = note.isChecklist
                reminderAt = note.reminderAt
                lockTimeoutSecs = note.lockTimeoutSecs
                lockOn = note.isLocked
            }
        }
        // Restore an auto-saved draft (from a previous minimise/close) if present.
        vm.loadDraft(noteId)?.let { d ->
            title = d.title
            content = d.content
            categoryId = d.categoryId
            colorIndex = d.color
            isChecklist = d.isChecklist
            reminderAt = d.reminderAt
            lockTimeoutSecs = d.lockTimeoutSecs
            lockOn = d.isLocked
            dirty = true   // a restored draft is unsaved work
        }
        loaded = true
    }

    // Auto-save a draft whenever the note has been edited (debounced).
    LaunchedEffect(title, content, categoryId, colorIndex, isChecklist, reminderAt, lockTimeoutSecs, lockOn, dirty) {
        if (dirty && (title.isNotBlank() || content.isNotBlank())) {
            delay(400)
            vm.saveDraft(noteId, title, content, categoryId, colorIndex, isChecklist, reminderAt, lockTimeoutSecs, lockOn)
        }
    }

    fun doSave() {
        vm.saveNote(noteId, title.trim(), content.trim(), categoryId, colorIndex, isChecklist, reminderAt, lockTimeoutSecs, lockOn)
        vm.clearDraft()
        onDone()
    }

    // Ask before leaving with unsaved changes; otherwise just close.
    fun attemptClose() {
        if (dirty && (title.isNotBlank() || content.isNotBlank())) {
            showExitDialog = true
        } else {
            // Only clear the draft if THIS note was edited this session (dirty) —
            // an untouched note must not wipe another note's saved draft.
            if (dirty) vm.clearDraft()
            onDone()
        }
    }

    // Mobile back button → same unsaved-changes guard as the on-screen back arrow.
    BackHandler { attemptClose() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(if (noteId == 0L) "New Note" else "Edit Note") },
                navigationIcon = {
                    IconButton(onClick = { attemptClose() }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    TextButton(
                        onClick = { doSave() },
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
                onValueChange = { title = it; dirty = true },
                label = { Text("Title (optional)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(Modifier.size(12.dp))
            OutlinedTextField(
                value = content,
                onValueChange = { content = it; dirty = true },
                label = { Text(if (isChecklist) "One item per line" else "Text to save & copy") },
                modifier = Modifier
                    .fillMaxWidth()
                    .weight(1f)
            )
            Spacer(Modifier.size(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(modifier = Modifier.weight(1f)) {
                    Text("Checklist", style = MaterialTheme.typography.bodyLarge)
                    Text(
                        "Each line becomes a tickable item",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                Switch(checked = isChecklist, onCheckedChange = { isChecklist = it; dirty = true })
            }
            Spacer(Modifier.size(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(modifier = Modifier.weight(1f)) {
                    Text("Reminder", style = MaterialTheme.typography.bodyLarge)
                    Text(
                        reminderAt?.let {
                            SimpleDateFormat("MMM d, yyyy • h:mm a", Locale.getDefault()).format(it)
                        } ?: "No reminder set",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                if (reminderAt != null) {
                    TextButton(onClick = { reminderAt = null; dirty = true }) { Text("Clear") }
                }
                OutlinedButton(onClick = {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        notifPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                    }
                    pickReminder()
                }) { Text("Set") }
            }
            Spacer(Modifier.size(12.dp))
            Text("Lock this note", style = MaterialTheme.typography.labelLarge)
            if (!vm.lockEnabled) {
                Text(
                    "Set a master password in Settings first to lock notes.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            } else {
                Text(
                    "Off = not locked. Immediate = locks the moment you leave. " +
                        "10s–5 min = re-locks after that time. Manual = stays open until you lock it.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
                Spacer(Modifier.size(6.dp))
                // label, isLocked, timeoutSecs
                val lockModes = listOf(
                    Triple("Off", false, 0),
                    Triple("Immediate", true, -1),
                    Triple("10s", true, 10),
                    Triple("30s", true, 30),
                    Triple("1 min", true, 60),
                    Triple("5 min", true, 300),
                    Triple("Manual", true, 0)
                )
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(lockModes) { (label, on, secs) ->
                        FilterChip(
                            selected = lockOn == on && lockTimeoutSecs == secs,
                            onClick = { lockOn = on; lockTimeoutSecs = secs; dirty = true },
                            label = { Text(label) }
                        )
                    }
                }
            }

            if (categories.isNotEmpty()) {
                Spacer(Modifier.size(12.dp))
                Text("Category", style = androidx.compose.material3.MaterialTheme.typography.labelLarge)
                Spacer(Modifier.size(6.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    item {
                        FilterChip(
                            selected = categoryId == null,
                            onClick = { categoryId = null; dirty = true },
                            label = { Text("None") }
                        )
                    }
                    items(categories, key = { it.id }) { cat ->
                        FilterChip(
                            selected = categoryId == cat.id,
                            onClick = { categoryId = cat.id; dirty = true },
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
                                .clickable { colorIndex = index; dirty = true }
                        )
                    }
                }
            }
        }
    }

    if (showExitDialog) {
        AlertDialog(
            onDismissRequest = { showExitDialog = false },
            title = { Text("Save changes?") },
            text = { Text("This note has unsaved changes. Save before closing?") },
            confirmButton = {
                TextButton(onClick = { showExitDialog = false; doSave() }) { Text("Save") }
            },
            dismissButton = {
                Row {
                    TextButton(onClick = {
                        showExitDialog = false
                        vm.clearDraft()
                        onDone()
                    }) { Text("Discard") }
                    TextButton(onClick = { showExitDialog = false }) { Text("Cancel") }
                }
            }
        )
    }
}
