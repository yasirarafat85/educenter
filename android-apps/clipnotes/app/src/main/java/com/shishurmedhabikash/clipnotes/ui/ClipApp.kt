package com.shishurmedhabikash.clipnotes.ui

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
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.ContentPaste
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.Folder
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.DrawerValue
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
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
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.material3.rememberDrawerState
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.shishurmedhabikash.clipnotes.ui.screens.CategoriesScreen
import com.shishurmedhabikash.clipnotes.ui.screens.EditNoteScreen
import com.shishurmedhabikash.clipnotes.ui.screens.NotesList
import com.shishurmedhabikash.clipnotes.ui.screens.SettingsScreen
import com.shishurmedhabikash.clipnotes.ui.screens.TrashScreen
import kotlinx.coroutines.launch

private const val NOT_EDITING = Long.MIN_VALUE

enum class Screen(val title: String) {
    Notes("All Notes"),
    Favorites("Favorites"),
    Categories("Categories"),
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

    val notes by vm.notes.collectAsState()
    val favorites by vm.favorites.collectAsState()
    val trashed by vm.trashed.collectAsState()
    val categories by vm.categories.collectAsState()

    if (editingId != NOT_EDITING) {
        EditNoteScreen(
            vm = vm,
            noteId = editingId,
            categories = categories,
            onDone = { editingId = NOT_EDITING }
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

    ModalNavigationDrawer(
        drawerState = drawerState,
        drawerContent = {
            ModalDrawerSheet {
                DrawerHeader()
                Spacer(Modifier.size(12.dp))
                DrawerRow(Icons.Filled.Description, "All Notes", screen == Screen.Notes) { goTo(Screen.Notes) }
                DrawerRow(Icons.Filled.Favorite, "Favorites", screen == Screen.Favorites) { goTo(Screen.Favorites) }
                DrawerRow(Icons.Filled.Folder, "Categories", screen == Screen.Categories) { goTo(Screen.Categories) }
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
                            emptyText = "No notes yet. Tap the + button to save your first text.",
                            onCopy = { copyToClipboard(context, it.content.ifBlank { it.title }) },
                            onEdit = { editingId = it.id },
                            onToggleFavorite = { vm.toggleFavorite(it) },
                            onShare = { shareText(context, it.content.ifBlank { it.title }) },
                            onTrash = { vm.moveToTrash(it) }
                        )
                    }
                    Screen.Favorites -> NotesList(
                        notes = favorites,
                        categories = categories,
                        query = query,
                        emptyText = "No favorites yet. Tap the star on any note to add it here.",
                        onCopy = { copyToClipboard(context, it.content.ifBlank { it.title }) },
                        onEdit = { editingId = it.id },
                        onToggleFavorite = { vm.toggleFavorite(it) },
                        onShare = { shareText(context, it.content.ifBlank { it.title }) },
                        onTrash = { vm.moveToTrash(it) }
                    )
                    Screen.Categories -> CategoriesScreen(
                        categories = categories,
                        onOpen = { screenName = Screen.Notes.name; categoryFilter = it.id },
                        onAdd = { vm.addCategory(it) },
                        onRename = { c, n -> vm.renameCategory(c, n) },
                        onDelete = { vm.deleteCategory(it) }
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
            .background(
                Brush.horizontalGradient(listOf(Color(0xFF2563EB), Color(0xFF1E40AF)))
            )
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
