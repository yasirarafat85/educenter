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
import androidx.room.withTransaction
import com.yasirarafat.clipnotes.data.AppDatabase
import com.yasirarafat.clipnotes.data.Category
import com.yasirarafat.clipnotes.data.Checklist
import com.yasirarafat.clipnotes.data.License
import com.yasirarafat.clipnotes.data.Note
import com.yasirarafat.clipnotes.reminder.ReminderScheduler
import com.yasirarafat.clipnotes.widget.ClipWidgetProvider
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

    private val db = AppDatabase.get(app)
    private val dao = db.dao()
    private val prefs = app.getSharedPreferences("settings", Context.MODE_PRIVATE)

    val notes: StateFlow<List<Note>> =
        dao.activeNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val favorites: StateFlow<List<Note>> =
        dao.favoriteNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val trashed: StateFlow<List<Note>> =
        dao.trashedNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val categories: StateFlow<List<Category>> =
        dao.categories().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())
    val reminders: StateFlow<List<Note>> =
        dao.reminderNotes().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

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

    // Accent colour index (see ui.theme.ClipAccents)
    var accentIndex by mutableStateOf(prefs.getInt("accent", 0))
        private set

    fun setAccent(index: Int) {
        accentIndex = index
        prefs.edit().putInt("accent", index).apply()
    }

    // Text shared into the app from another app (via the Android share sheet)
    var pendingSharedText by mutableStateOf<String?>(null)
        private set

    // App lock (master password). lockEnabled = a password is set.
    var lockEnabled by mutableStateOf(prefs.getString("lock_hash", null) != null)
        private set
    // Ids of locked notes that are temporarily revealed (per-note, in memory only).
    var revealedIds by mutableStateOf<Set<Long>>(emptySet())
        private set
    private val relockJobs = mutableMapOf<Long, Job>()
    // Revealed notes whose timer is "Immediate" (-1): re-lock as soon as you leave the app.
    private val immediateIds = mutableSetOf<Long>()

    fun isRevealed(id: Long): Boolean = id in revealedIds

    /**
     * Reveal one locked note.
     *  - timeout > 0  : auto re-lock after that many seconds.
     *  - timeout == -1: "Immediate" — re-locks the moment you leave the app.
     *  - timeout == 0 : "Manual" — stays open until you lock it yourself.
     */
    fun revealNote(note: Note) {
        revealedIds = revealedIds + note.id
        relockJobs.remove(note.id)?.cancel()
        immediateIds.remove(note.id)
        when {
            note.lockTimeoutSecs > 0 -> {
                relockJobs[note.id] = viewModelScope.launch {
                    delay(note.lockTimeoutSecs * 1000L)
                    relockNote(note.id)
                }
            }
            note.lockTimeoutSecs == -1 -> immediateIds.add(note.id)
        }
    }

    /** Hide one note again right now (manual re-lock). */
    fun relockNote(id: Long) {
        relockJobs.remove(id)?.cancel()
        immediateIds.remove(id)
        revealedIds = revealedIds - id
    }

    /** Re-lock "Immediate" notes when the app goes to the background. */
    fun relockOnLeave() {
        if (immediateIds.isEmpty()) return
        val ids = immediateIds.toList()
        ids.forEach { relockNote(it) }
    }

    /** Re-lock every revealed note (used by Settings "Lock now"). */
    fun lockAll() {
        relockJobs.values.forEach { it.cancel() }
        relockJobs.clear()
        immediateIds.clear()
        revealedIds = emptySet()
    }

    // Fingerprint unlock (only meaningful while a master password is set).
    var biometricEnabled by mutableStateOf(prefs.getBoolean("biometric", false))
        private set

    fun enableBiometric(on: Boolean) {
        biometricEnabled = on
        prefs.edit().putBoolean("biometric", on).apply()
    }

    private fun hashPw(pw: String): String {
        val digest = java.security.MessageDigest.getInstance("SHA-256")
            .digest(("clipnotes-lock-salt::" + pw).toByteArray(Charsets.UTF_8))
        return digest.joinToString("") { "%02x".format(it.toInt() and 0xFF) }
    }

    fun setMasterPassword(pw: String) {
        if (pw.isBlank()) return
        prefs.edit().putString("lock_hash", hashPw(pw)).apply()
        lockEnabled = true
    }

    fun verifyMaster(pw: String): Boolean {
        val stored = prefs.getString("lock_hash", null) ?: return false
        return stored == hashPw(pw)
    }

    fun changeMasterPassword(old: String, new: String): Boolean {
        if (!verifyMaster(old) || new.isBlank()) return false
        prefs.edit().putString("lock_hash", hashPw(new)).apply()
        return true
    }

    fun removeMasterPassword(current: String): Boolean {
        if (!verifyMaster(current)) return false
        prefs.edit().remove("lock_hash").putBoolean("biometric", false).apply()
        lockEnabled = false
        biometricEnabled = false
        lockAll()
        viewModelScope.launch { dao.unlockAllNotes() } // nothing to gate anymore
        return true
    }

    fun toggleNoteLock(note: Note) = viewModelScope.launch {
        val nowLocked = !note.isLocked
        dao.updateNote(note.copy(isLocked = nowLocked, updatedAt = System.currentTimeMillis()))
        // Locking a note hides it immediately; unlocking clears any reveal state.
        relockNote(note.id)
        scheduleAutoBackup()
    }

    fun setTheme(mode: Int) {
        themeMode = mode
        prefs.edit().putInt("theme", mode).apply()
    }

    fun setSort(mode: Int) {
        sortMode = mode
        prefs.edit().putInt("sort", mode).apply()
    }

    fun stashSharedText(text: String) {
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

    fun saveNote(
        id: Long,
        title: String,
        content: String,
        categoryId: Long?,
        color: Int = 0,
        isChecklist: Boolean = false,
        reminderAt: Long? = null,
        lockTimeoutSecs: Int = 0,
        isLocked: Boolean = false
    ) = viewModelScope.launch {
        val savedId: Long
        if (id == 0L) {
            savedId = dao.insertNote(
                Note(
                    title = title, content = content, categoryId = categoryId,
                    color = color, isChecklist = isChecklist, reminderAt = reminderAt,
                    lockTimeoutSecs = lockTimeoutSecs, isLocked = isLocked,
                    updatedAt = System.currentTimeMillis()
                )
            )
        } else {
            val existing = dao.getNote(id) ?: return@launch
            dao.updateNote(
                existing.copy(
                    title = title, content = content, categoryId = categoryId,
                    color = color, isChecklist = isChecklist, reminderAt = reminderAt,
                    lockTimeoutSecs = lockTimeoutSecs, isLocked = isLocked,
                    updatedAt = System.currentTimeMillis()
                )
            )
            savedId = id
        }
        // "Immediate" lock: hide the note the moment it's saved.
        if (isLocked && lockTimeoutSecs == -1) relockNote(savedId)
        // If the note is no longer locked, clear any reveal/timer state.
        if (!isLocked) relockNote(savedId)
        val app = getApplication<Application>()
        if (reminderAt != null && reminderAt > System.currentTimeMillis()) {
            ReminderScheduler.schedule(app, savedId, title, content, reminderAt)
        } else {
            ReminderScheduler.cancel(app, savedId)
        }
        scheduleAutoBackup()
    }

    /** Tick/untick a checklist item straight from the card. */
    fun toggleChecklistItem(note: Note, index: Int) = viewModelScope.launch {
        val newContent = Checklist.toggleAt(note.content, index)
        dao.updateNote(note.copy(content = newContent, updatedAt = System.currentTimeMillis()))
        scheduleAutoBackup()
    }

    fun toggleFavorite(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isFavorite = !note.isFavorite)); scheduleAutoBackup()
    }

    fun moveToTrash(note: Note) = viewModelScope.launch {
        ReminderScheduler.cancel(getApplication(), note.id)
        dao.updateNote(note.copy(isTrashed = true, isFavorite = false, updatedAt = System.currentTimeMillis())); scheduleAutoBackup()
    }

    fun restore(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isTrashed = false, updatedAt = System.currentTimeMillis()))
        note.reminderAt?.let { if (it > System.currentTimeMillis()) ReminderScheduler.schedule(getApplication(), note.id, note.title, note.content, it) }
        scheduleAutoBackup()
    }

    fun deleteForever(note: Note) = viewModelScope.launch {
        ReminderScheduler.cancel(getApplication(), note.id)
        dao.deleteNoteHard(note.id); scheduleAutoBackup()
    }

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
    // Local backup: an automatic copy in the app's own storage (no file picker,
    // no path shown), refreshed on every change. Cloud backup: an optional copy
    // in a file the user picks (Google Drive or phone).

    private fun localBackupFile(): java.io.File =
        java.io.File(getApplication<Application>().filesDir, "clipnotes-backup.json")

    fun hasLocalBackup(): Boolean = localBackupFile().let { it.exists() && it.length() > 2 }

    /** Local: restore from the automatic on-phone backup (no path, one tap). */
    fun restoreLocalNow(onResult: (Int) -> Unit) = viewModelScope.launch {
        val count = withContext(Dispatchers.IO) {
            try {
                val f = localBackupFile()
                if (!f.exists()) return@withContext -1
                applyBackupJson(f.readText(Charsets.UTF_8))
            } catch (e: Exception) { -1 }
        }
        ClipWidgetProvider.notifyDataChanged(getApplication())
        onResult(count)
    }

    /** Remember a cloud file the user picked (via CreateDocument). */
    fun configureBackupFile(uri: Uri, onResult: (Boolean) -> Unit) {
        try {
            getApplication<Application>().contentResolver.takePersistableUriPermission(
                uri,
                Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION
            )
        } catch (_: Exception) { /* some providers don't support persistable grants */ }
        backupUri = uri.toString()
        // Do NOT auto-enable mirroring: keep the file a restore point the user controls.
        prefs.edit().putString("backup_uri", backupUri).apply()
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
        // Keep any home-screen widgets in sync whenever notes change.
        ClipWidgetProvider.notifyDataChanged(getApplication())
        autoBackupJob?.cancel()
        autoBackupJob = viewModelScope.launch {
            delay(2500) // debounce a burst of edits into one write
            withContext(Dispatchers.IO) {
                try {
                    // Check the DB directly; never overwrite a good backup with an empty one.
                    if (dao.allNotesOnce().isEmpty()) return@withContext
                    val json = buildBackupJson()
                    // 1) Always refresh the automatic local backup.
                    localBackupFile().writeText(json, Charsets.UTF_8)
                    // 2) Mirror to the cloud file too, if the user turned sync on.
                    val u = backupUri
                    if (autoBackup && u != null) {
                        getApplication<Application>().contentResolver.openOutputStream(Uri.parse(u), "wt")?.use {
                            it.write(json.toByteArray(Charsets.UTF_8))
                        }
                    }
                } catch (_: Exception) {}
            }
        }
    }

    /** Export to any uri (used by "Export to file" and by the remembered-file backup). */
    fun exportTo(uri: Uri, onResult: (Boolean) -> Unit) = writeBackup(uri, onResult)

    /** Import from any uri (used by "Import from file"). */
    fun importFrom(uri: Uri, onResult: (Int) -> Unit) = readBackup(uri, onResult)

    private fun writeBackup(uri: Uri, onResult: (Boolean) -> Unit) = viewModelScope.launch {
        val ok = withContext(Dispatchers.IO) {
            try {
                val json = buildBackupJson()
                getApplication<Application>().contentResolver.openOutputStream(uri, "wt")?.use { os ->
                    os.write(json.toByteArray(Charsets.UTF_8))
                }
                true
            } catch (e: Exception) {
                false
            }
        }
        onResult(ok)
    }

    private fun readBackup(uri: Uri, onResult: (Int) -> Unit) = viewModelScope.launch {
        val count = withContext(Dispatchers.IO) {
            try {
                val text = getApplication<Application>().contentResolver.openInputStream(uri)?.use {
                    it.readBytes().toString(Charsets.UTF_8)
                } ?: return@withContext -1
                applyBackupJson(text)
            } catch (e: Exception) {
                -1
            }
        }
        ClipWidgetProvider.notifyDataChanged(getApplication())
        onResult(count)
    }

    /** Build the backup JSON from the current database. */
    private suspend fun buildBackupJson(): String {
        val cats = dao.allCategoriesOnce()
        val allNotes = dao.allNotesOnce()
        val catNameById = cats.associateBy({ it.id }, { it.name })

        val root = JSONObject()
        root.put("app", "ClipNotes")
        root.put("version", 2)
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
            o.put("pinned", n.isPinned)
            o.put("copyCount", n.copyCount)
            o.put("color", n.color)
            o.put("checklist", n.isChecklist)
            o.put("locked", n.isLocked)
            o.put("lockTimeoutSecs", n.lockTimeoutSecs)
            o.put("reminderAt", n.reminderAt ?: JSONObject.NULL)
            noteArr.put(o)
        }
        root.put("notes", noteArr)
        return root.toString(2)
    }

    /**
     * REPLACE-style restore: replaces current notes+categories with the backup.
     * Runs inside a DB transaction so a mid-way failure rolls back (no data loss),
     * and refuses to wipe anything if the backup has no notes.
     * Returns notes restored, -2 if the backup is empty, or -1 on failure.
     */
    private suspend fun applyBackupJson(text: String): Int {
        var importedTheme: Int? = null
        val restored: Int
        try {
            val root = JSONObject(text)
            val noteArr = root.optJSONArray("notes") ?: JSONArray()

            // Safety: never delete the user's notes for an empty / invalid backup.
            var valid = 0
            for (i in 0 until noteArr.length()) {
                val o = noteArr.getJSONObject(i)
                if (o.optString("title").isNotBlank() || o.optString("content").isNotBlank()) valid++
            }
            if (valid == 0) return -2

            root.optJSONObject("settings")?.let { s ->
                if (s.has("theme")) importedTheme = s.optInt("theme", themeMode)
            }

            restored = db.withTransaction {
                dao.deleteAllNotes()
                dao.deleteAllCategories()

                val nameToId = HashMap<String, Long>()
                root.optJSONArray("categories")?.let { catArr ->
                    for (i in 0 until catArr.length()) {
                        val name = catArr.getJSONObject(i).optString("name").trim()
                        if (name.isNotEmpty() && !nameToId.containsKey(name)) {
                            nameToId[name] = dao.insertCategory(Category(name = name))
                        }
                    }
                }

                var count = 0
                for (i in 0 until noteArr.length()) {
                    val o = noteArr.getJSONObject(i)
                    val title = o.optString("title", "")
                    val content = o.optString("content", "")
                    if (title.isBlank() && content.isBlank()) continue
                    val catName = if (o.isNull("category")) null else o.optString("category").ifBlank { null }
                    val catId = catName?.let { name ->
                        nameToId[name] ?: dao.insertCategory(Category(name = name)).also { nameToId[name] = it }
                    }
                    val reminderAt = if (o.isNull("reminderAt")) null else o.optLong("reminderAt").takeIf { it > 0 }
                    val newId = dao.insertNote(
                        Note(
                            title = title,
                            content = content,
                            categoryId = catId,
                            isFavorite = o.optBoolean("favorite", false),
                            isTrashed = o.optBoolean("trashed", false),
                            isPinned = o.optBoolean("pinned", false),
                            copyCount = o.optInt("copyCount", 0),
                            color = o.optInt("color", 0),
                            isChecklist = o.optBoolean("checklist", false),
                            isLocked = o.optBoolean("locked", false),
                            lockTimeoutSecs = o.optInt("lockTimeoutSecs", 0),
                            reminderAt = reminderAt,
                            updatedAt = System.currentTimeMillis()
                        )
                    )
                    if (reminderAt != null && reminderAt > System.currentTimeMillis()) {
                        ReminderScheduler.schedule(getApplication(), newId, title, content, reminderAt)
                    }
                    count++
                }
                count
            }
        } catch (e: Exception) {
            return -1
        }
        importedTheme?.let { t -> withContext(Dispatchers.Main) { setTheme(t) } }
        return restored
    }
}
