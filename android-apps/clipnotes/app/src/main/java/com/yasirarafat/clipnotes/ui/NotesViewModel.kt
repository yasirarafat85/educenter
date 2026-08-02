package com.yasirarafat.clipnotes.ui

import android.app.Application
import android.content.Context
import android.content.Intent
import android.net.Uri
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.yasirarafat.clipnotes.data.AppDatabase
import com.yasirarafat.clipnotes.data.Category
import com.yasirarafat.clipnotes.data.License
import com.yasirarafat.clipnotes.data.Note
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject

class NotesViewModel(app: Application) : AndroidViewModel(app) {

    private val dao = AppDatabase.get(app).dao()
    private val prefs = app.getSharedPreferences("settings", Context.MODE_PRIVATE)

    val notes: StateFlow<List<Note>> =
        dao.activeNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val favorites: StateFlow<List<Note>> =
        dao.favoriteNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val trashed: StateFlow<List<Note>> =
        dao.trashedNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val categories: StateFlow<List<Category>> =
        dao.categories().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    // 0 = follow system, 1 = light, 2 = dark
    var themeMode by mutableStateOf(prefs.getInt("theme", 0))
        private set

    // Pro (unlocked by activation key)
    var isPro by mutableStateOf(prefs.getBoolean("pro_unlocked", false))
        private set

    // Remembered backup file (so backups don't need the picker every time)
    var backupUri by mutableStateOf(prefs.getString("backup_uri", null))
        private set
    var autoBackup by mutableStateOf(prefs.getBoolean("auto_backup", false))
        private set

    private var autoBackupJob: Job? = null

    // Sort mode for the notes list: 0 = recent, 1 = alphabetical, 2 = most copied
    var sortMode by mutableStateOf(prefs.getInt("sort", 0))
        private set

    // Text shared into the app from another app (via the Android share sheet)
    var pendingSharedText by mutableStateOf<String?>(null)
        private set

    fun setTheme(mode: Int) {
        themeMode = mode
        prefs.edit().putInt("theme", mode).apply()
    }

    fun setSort(mode: Int) {
        sortMode = mode
        prefs.edit().putInt("sort", mode).apply()
    }

    fun setPendingSharedText(text: String) {
        pendingSharedText = text
    }

    fun consumePendingSharedText() {
        pendingSharedText = null
    }

    fun togglePin(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isPinned = !note.isPinned)); scheduleAutoBackup()
    }

    fun onCopied(note: Note) = viewModelScope.launch {
        dao.incrementCopy(note.id)
    }

    fun deviceCode(): String = License.deviceCode(getApplication())

    /** Returns true if the key was valid for this device and Pro is now unlocked. */
    fun tryUnlock(key: String): Boolean {
        val ok = License.isValidKey(getApplication(), key)
        if (ok) {
            isPro = true
            prefs.edit().putBoolean("pro_unlocked", true).apply()
        }
        return ok
    }

    suspend fun loadNote(id: Long): Note? = dao.getNote(id)

    fun saveNote(id: Long, title: String, content: String, categoryId: Long?) = viewModelScope.launch {
        if (id == 0L) {
            dao.insertNote(
                Note(title = title, content = content, categoryId = categoryId, updatedAt = System.currentTimeMillis())
            )
        } else {
            val existing = dao.getNote(id) ?: return@launch
            dao.updateNote(
                existing.copy(title = title, content = content, categoryId = categoryId, updatedAt = System.currentTimeMillis())
            )
        }
        scheduleAutoBackup()
    }

    fun toggleFavorite(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isFavorite = !note.isFavorite)); scheduleAutoBackup()
    }

    fun moveToTrash(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isTrashed = true, isFavorite = false, updatedAt = System.currentTimeMillis())); scheduleAutoBackup()
    }

    fun restore(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isTrashed = false, updatedAt = System.currentTimeMillis())); scheduleAutoBackup()
    }

    fun deleteForever(note: Note) = viewModelScope.launch { dao.deleteNoteHard(note.id); scheduleAutoBackup() }

    fun emptyTrash() = viewModelScope.launch { dao.emptyTrash(); scheduleAutoBackup() }

    fun addCategory(name: String) = viewModelScope.launch { dao.insertCategory(Category(name = name)); scheduleAutoBackup() }

    fun renameCategory(category: Category, name: String) = viewModelScope.launch {
        dao.updateCategory(category.copy(name = name)); scheduleAutoBackup()
    }

    fun deleteCategory(category: Category) = viewModelScope.launch {
        dao.clearCategoryFromNotes(category.id)
        dao.deleteCategory(category.id)
        scheduleAutoBackup()
    }

    // ---------------- Backup / Restore ----------------

    /** Remember a file the user picked (via CreateDocument) and turn auto backup on. */
    fun configureBackupFile(uri: Uri, onResult: (Boolean) -> Unit) {
        try {
            getApplication<Application>().contentResolver.takePersistableUriPermission(
                uri,
                Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION
            )
        } catch (_: Exception) { /* some providers don't support persistable grants */ }
        backupUri = uri.toString()
        autoBackup = true
        prefs.edit().putString("backup_uri", backupUri).putBoolean("auto_backup", true).apply()
        writeBackup(uri, onResult)
    }

    fun setAutoBackupEnabled(on: Boolean) {
        autoBackup = on
        prefs.edit().putBoolean("auto_backup", on).apply()
    }

    fun clearBackupFile() {
        backupUri = null
        autoBackup = false
        prefs.edit().remove("backup_uri").putBoolean("auto_backup", false).apply()
    }

    /** One-tap backup to the remembered file (no picker). */
    fun backupNow(onResult: (Boolean) -> Unit) {
        val u = backupUri
        if (u == null) { onResult(false); return }
        writeBackup(Uri.parse(u), onResult)
    }

    /** One-tap restore from the remembered file (no picker). Returns notes imported or -1. */
    fun restoreNow(onResult: (Int) -> Unit) {
        val u = backupUri
        if (u == null) { onResult(-1); return }
        readBackup(Uri.parse(u), onResult)
    }

    private fun scheduleAutoBackup() {
        val u = backupUri
        if (!autoBackup || u == null) return
        autoBackupJob?.cancel()
        autoBackupJob = viewModelScope.launch {
            delay(2500) // debounce a burst of edits into one write
            writeBackup(Uri.parse(u)) { }
        }
    }

    /** Export to any uri (used by "Export to file" and by the remembered-file backup). */
    fun exportTo(uri: Uri, onResult: (Boolean) -> Unit) = writeBackup(uri, onResult)

    /** Import from any uri (used by "Import from file"). */
    fun importFrom(uri: Uri, onResult: (Int) -> Unit) = readBackup(uri, onResult)

    private fun writeBackup(uri: Uri, onResult: (Boolean) -> Unit) = viewModelScope.launch {
        val ok = withContext(Dispatchers.IO) {
            try {
                val cats = dao.allCategoriesOnce()
                val allNotes = dao.allNotesOnce()
                val catNameById = cats.associateBy({ it.id }, { it.name })

                val root = JSONObject()
                root.put("app", "ClipNotes")
                root.put("version", 2)

                // settings (theme only; Pro status is intentionally NOT exported)
                root.put("settings", JSONObject().put("theme", themeMode))

                val catArr = JSONArray()
                cats.forEach { catArr.put(JSONObject().put("name", it.name)) }
                root.put("categories", catArr)

                val noteArr = JSONArray()
                allNotes.forEach { n ->
                    val o = JSONObject()
                    o.put("title", n.title)
                    o.put("content", n.content)
                    o.put("category", n.categoryId?.let { catNameById[it] } ?: JSONObject.NULL)
                    o.put("favorite", n.isFavorite)
                    o.put("trashed", n.isTrashed)
                    noteArr.put(o)
                }
                root.put("notes", noteArr)

                getApplication<Application>().contentResolver.openOutputStream(uri, "wt")?.use { os ->
                    os.write(root.toString(2).toByteArray(Charsets.UTF_8))
                }
                true
            } catch (e: Exception) {
                false
            }
        }
        onResult(ok)
    }

    /** Returns number of notes imported, or -1 on failure. Skips exact duplicates. */
    private fun readBackup(uri: Uri, onResult: (Int) -> Unit) = viewModelScope.launch {
        var importedTheme: Int? = null
        val count = withContext(Dispatchers.IO) {
            try {
                val text = getApplication<Application>().contentResolver.openInputStream(uri)?.use {
                    it.readBytes().toString(Charsets.UTF_8)
                } ?: return@withContext -1

                val root = JSONObject(text)

                root.optJSONObject("settings")?.let { s ->
                    if (s.has("theme")) importedTheme = s.optInt("theme", themeMode)
                }

                root.optJSONArray("categories")?.let { catArr ->
                    for (i in 0 until catArr.length()) {
                        val name = catArr.getJSONObject(i).optString("name").trim()
                        if (name.isNotEmpty() && dao.categoryIdByName(name) == null) {
                            dao.insertCategory(Category(name = name))
                        }
                    }
                }

                val noteArr = root.optJSONArray("notes") ?: JSONArray()
                var inserted = 0
                for (i in 0 until noteArr.length()) {
                    val o = noteArr.getJSONObject(i)
                    val title = o.optString("title", "")
                    val content = o.optString("content", "")
                    if (title.isBlank() && content.isBlank()) continue
                    val trashed = o.optBoolean("trashed", false)
                    if (dao.countMatchingNotes(title, content, trashed) > 0) continue // skip duplicates
                    val catName = if (o.isNull("category")) null else o.optString("category").ifBlank { null }
                    val catId = catName?.let {
                        dao.categoryIdByName(it) ?: dao.insertCategory(Category(name = it))
                    }
                    dao.insertNote(
                        Note(
                            title = title,
                            content = content,
                            categoryId = catId,
                            isFavorite = o.optBoolean("favorite", false),
                            isTrashed = trashed,
                            updatedAt = System.currentTimeMillis()
                        )
                    )
                    inserted++
                }
                inserted
            } catch (e: Exception) {
                -1
            }
        }
        importedTheme?.let { setTheme(it) }
        onResult(count)
    }
}
