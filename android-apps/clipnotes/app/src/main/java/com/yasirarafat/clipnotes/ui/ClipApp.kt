package com.yasirarafat.clipnotes.ui

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ContentPaste
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.Folder
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.SwapVert
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.TextButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalDrawerSheet
import androidx.compose.material3.ModalNavigationDrawer
import androidx.compose.material3.NavigationDrawerItem
import androidx.compose.material3.NavigationDrawerItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.material3.rememberDrawerState
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.data.Checklist
import com.yasirarafat.clipnotes.data.Note
import com.yasirarafat.clipnotes.ui.screens.CategoriesScreen
import com.yasirarafat.clipnotes.ui.screens.EditNoteScreen
import com.yasirarafat.clipnotes.ui.screens.NotesList
import com.yasirarafat.clipnotes.ui.screens.RemindersScreen
import com.yasirarafat.clipnotes.ui.screens.SettingsScreen
import com.yasirarafat.clipnotes.ui.screens.TrashScreen
import kotlinx.coroutines.launch

private const val NOT_EDITING = Long.MIN_VALUE

enum class Screen(val title: String) {
    Notes("All Notes"),
    Favorites("Favorites"),
    Categories("Categories"),
    Reminders("Reminders"),
    Trash("Trash"),
    Settings("Settings")
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ClipApp(vm: NotesViewModel) {
    val context = LocalContext.current
    val drawerState = rememberDrawerState(DrawerValue.Closed)
    val scope = rememberCoroutineScope()

    var screenName by rememberSaveable { mutableStateOf(Screen.Notes.name) }
    val screen = Screen.valueOf(screenName)
    var editingId by rememberSaveable { mutableStateOf(NOT_EDITING) }
    var categoryFilter by rememberSaveable { mutableStateOf(-1L) }
    var searchActive by rememberSaveable { mutableStateOf(false) }
    var query by rememberSaveable { mutableStateOf("") }
    var sortMenuOpen by remember { mutableStateOf(false) }
    var showUnlock by remember { mutableStateOf(false) }

    val notes by vm.notes.collectAsState()
    val favorites by vm.favorites.collectAsState()
    val trashed by vm.trashed.collectAsState()
    val categories by vm.categories.collectAsState()
    val reminders by vm.reminders.collectAsState()

    // Text shared into the app from another app opens a new note, prefilled.
    LaunchedEffect(vm.pendingSharedText) {
        if (vm.pendingSharedText != null && editingId == NOT_EDITING) editingId = 0L
    }

    if (editingId != NOT_EDITING) {
        EditNoteScreen(
            vm = vm,
            noteId = editingId,
            categories = categories,
            initialContent = if (editingId == 0L) vm.pendingSharedText else null,
            onDone = { editingId = NOT_EDITING; vm.consumePendingSharedText() }
        )
        return
    }

    fun goTo(target: Screen) {
        screenName = target.name
        categoryFilter = -1L
        searchActive = false
        query = ""
        scope.launch { drawerState.close() }
    }

    fun onToggleLock(note: Note) {
        if (vm.lockEnabled) {
            vm.toggleNoteLock(note)
        } else {
            Toast.makeText(context, "Set a master password in Settings first", Toast.LENGTH_SHORT).show()
        }
    }

    if (showUnlock) {
        UnlockDialog(
            onDismiss = { showUnlock = false },
            onUnlock = { pw ->
                if (vm.unlockSession(pw)) {
                    showUnlock = false; true
                } else false
            }
        )
    }

    ModalNavigationDrawer(
        drawerState = drawerState,
        drawerContent = {
            ModalDrawerSheet {
                DrawerHeader()
                Spacer(Modifier.size(12.dp))
                DrawerRow(Icons.Filled.Description, "All Notes", screen == Screen.Notes) { goTo(Screen.Notes) }
                DrawerRow(Icons.Filled.Favorite, "Favorites", screen == Screen.Favorites) { goTo(Screen.Favorites) }
                DrawerRow(Icons.Filled.Folder, "Categories", screen == Screen.Categories) { goTo(Screen.Categories) }
                DrawerRow(Icons.Filled.Notifications, "Reminders", screen == Screen.Reminders) { goTo(Screen.Reminders) }
                DrawerRow(Icons.Filled.Delete, "Trash", screen == Screen.Trash) { goTo(Screen.Trash) }
                DrawerRow(Icons.Filled.Settings, "Settings", screen == Screen.Settings) { goTo(Screen.Settings) }
            }
        }
    ) {
        Scaffold(
            topBar = {
                TopAppBar(
                    navigationIcon = {
                        IconButton(onClick = { scope.launch { drawerState.open() } }) {
                            Icon(Icons.Filled.Menu, contentDescription = "Menu")
                        }
                    },
                    title = {
                        if (searchActive && (screen == Screen.Notes || screen == Screen.Favorites)) {
                            TextField(
                                value = query,
                                onValueChange = { query = it },
                                placeholder = { Text("Search notes...") },
                                singleLine = true,
                                modifier = Modifier.fillMaxWidth(),
                                colors = TextFieldDefaults.colors(
                                    focusedContainerColor = Color.Transparent,
                                    unfocusedContainerColor = Color.Transparent,
                                    focusedIndicatorColor = Color.White,
                                    unfocusedIndicatorColor = Color.White.copy(alpha = 0.5f),
                                    focusedTextColor = Color.White,
                                    unfocusedTextColor = Color.White,
                                    cursorColor = Color.White,
                                    focusedPlaceholderColor = Color.White.copy(alpha = 0.7f),
                                    unfocusedPlaceholderColor = Color.White.copy(alpha = 0.7f)
                                )
                            )
                        } else {
                            val label = if (screen == Screen.Notes && categoryFilter > 0) {
                                categories.firstOrNull { it.id == categoryFilter }?.name ?: "Notes"
                            } else {
                                screen.title
                            }
                            Text(label)
                        }
                    },
                    actions = {
                        if (screen == Screen.Notes || screen == Screen.Favorites) {
                            Box {
                                IconButton(onClick = { sortMenuOpen = true }) {
                                    Icon(Icons.Filled.SwapVert, contentDescription = "Sort")
                                }
                                DropdownMenu(
                                    expanded = sortMenuOpen,
                                    onDismissRequest = { sortMenuOpen = false }
                                ) {
                                    listOf("Recent first" to 0, "A–Z" to 1, "Most copied" to 2)
                                        .forEach { (label, mode) ->
                                            DropdownMenuItem(
                                                text = { Text(label) },
                                                trailingIcon = {
                                                    if (vm.sortMode == mode) Icon(Icons.Filled.Check, null)
                                                },
                                                onClick = { vm.setSort(mode); sortMenuOpen = false }
                                            )
                                        }
                                }
                            }
                            IconButton(onClick = {
                                if (searchActive) {
                                    searchActive = false; query = ""
                                } else {
                                    searchActive = true
                                }
                            }) {
                                Icon(
                                    if (searchActive) Icons.Filled.Close else Icons.Filled.Search,
                                    contentDescription = "Search"
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
            floatingActionButton = {
                if (screen == Screen.Notes || screen == Screen.Favorites) {
                    FloatingActionButton(onClick = { editingId = 0L }) {
                        Icon(Icons.Filled.Add, contentDescription = "New note")
                    }
                }
            }
        ) { padding ->
            Box(modifier = Modifier.padding(padding)) {
                when (screen) {
                    Screen.Notes -> {
                        val list = if (categoryFilter > 0) notes.filter { it.categoryId == categoryFilter } else notes
                        NotesList(
                            notes = list,
                            categories = categories,
                            query = query,
                            sortMode = vm.sortMode,
                            emptyText = "No notes yet. Tap the + button to save your first text.",
                            onCopy = { copyToClipboard(context, noteCopyText(it)); vm.onCopied(it) },
                            onEdit = { editingId = it.id },
                            onToggleFavorite = { vm.toggleFavorite(it) },
                            onTogglePin = { vm.togglePin(it) },
                            onShare = { shareText(context, noteCopyText(it)) },
                            onTrash = { vm.moveToTrash(it) },
                            onToggleChecklistItem = { note, idx -> vm.toggleChecklistItem(note, idx) },
                            sessionUnlocked = vm.unlocked,
                            onRequestUnlock = { showUnlock = true },
                            onToggleLock = { onToggleLock(it) }
                        )
                    }
                    Screen.Favorites -> NotesList(
                        notes = favorites,
                        categories = categories,
                        query = query,
                        sortMode = vm.sortMode,
                        emptyText = "No favorites yet. Tap the star on any note to add it here.",
                        onCopy = { copyToClipboard(context, noteCopyText(it)); vm.onCopied(it) },
                        onEdit = { editingId = it.id },
                        onToggleFavorite = { vm.toggleFavorite(it) },
                        onTogglePin = { vm.togglePin(it) },
                        onShare = { shareText(context, noteCopyText(it)) },
                        onTrash = { vm.moveToTrash(it) },
                        onToggleChecklistItem = { note, idx -> vm.toggleChecklistItem(note, idx) },
                        sessionUnlocked = vm.unlocked,
                        onRequestUnlock = { showUnlock = true },
                        onToggleLock = { onToggleLock(it) }
                    )
                    Screen.Categories -> CategoriesScreen(
                        categories = categories,
                        onOpen = { screenName = Screen.Notes.name; categoryFilter = it.id },
                        onAdd = { vm.addCategory(it) },
                        onRename = { c, n -> vm.renameCategory(c, n) },
                        onDelete = { vm.deleteCategory(it) }
                    )
                    Screen.Reminders -> RemindersScreen(
                        reminders = reminders,
                        onOpen = { editingId = it.id }
                    )
                    Screen.Trash -> TrashScreen(
                        trashed = trashed,
                        categories = categories,
                        onRestore = { vm.restore(it) },
                        onDeleteForever = { vm.deleteForever(it) },
                        onEmpty = { vm.emptyTrash() }
                    )
                    Screen.Settings -> SettingsScreen(vm)
                }
            }
        }
    }
}

@Composable
private fun DrawerHeader() {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .background(MaterialTheme.colorScheme.primary)
            .padding(20.dp)
            .padding(top = 24.dp)
    ) {
        Column {
            Icon(
                Icons.Filled.ContentPaste,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.size(40.dp)
            )
            Spacer(Modifier.size(8.dp))
            Text(
                "Clip Notes",
                color = Color.White,
                style = MaterialTheme.typography.headlineSmall,
                fontWeight = FontWeight.Bold
            )
            Text(
                "Your quick clipboard",
                color = Color.White.copy(alpha = 0.85f),
                style = MaterialTheme.typography.bodyMedium
            )
        }
    }
}

@Composable
private fun DrawerRow(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    selected: Boolean,
    onClick: () -> Unit
) {
    NavigationDrawerItem(
        icon = { Icon(icon, contentDescription = null) },
        label = { Text(label) },
        selected = selected,
        onClick = onClick,
        modifier = Modifier.padding(NavigationDrawerItemDefaults.ItemPadding)
    )
}

@Composable
private fun UnlockDialog(onDismiss: () -> Unit, onUnlock: (String) -> Boolean) {
    var pw by remember { mutableStateOf("") }
    var error by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Unlock notes") },
        text = {
            Column {
                Text("Enter your master password to view locked notes.")
                Spacer(Modifier.size(8.dp))
                OutlinedTextField(
                    value = pw,
                    onValueChange = { pw = it; error = false },
                    label = { Text("Master password") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation()
                )
                if (error) {
                    Text(
                        "Wrong password",
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall
                    )
                }
            }
        },
        confirmButton = {
            TextButton(onClick = { if (!onUnlock(pw)) error = true }) { Text("Unlock") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

private fun noteCopyText(note: Note): String =
    if (note.isChecklist) Checklist.plainText(note.content) else note.content.ifBlank { note.title }

private fun copyToClipboard(context: Context, text: String) {
    if (text.isBlank()) return
    val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    cm.setPrimaryClip(ClipData.newPlainText("Clip Notes", text))
    // Android 13+ shows its own copy confirmation, so avoid a duplicate toast there.
    if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.S_V2) {
        Toast.makeText(context, "Copied to clipboard", Toast.LENGTH_SHORT).show()
    }
}

private fun shareText(context: Context, text: String) {
    if (text.isBlank()) return
    val intent = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_TEXT, text)
    }
    context.startActivity(Intent.createChooser(intent, "Share via"))
}
