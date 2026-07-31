package com.yasirarafat.clipnotes.ui

import android.app.Application
import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.yasirarafat.clipnotes.data.AppDatabase
import com.yasirarafat.clipnotes.data.Category
import com.yasirarafat.clipnotes.data.Note
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

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

    fun setTheme(mode: Int) {
        themeMode = mode
        prefs.edit().putInt("theme", mode).apply()
    }

    suspend fun loadNote(id: Long): Note? = dao.getNote(id)

    fun saveNote(id: Long, title: String, content: String, categoryId: Long?) = viewModelScope.launch {
        if (id == 0L) {
            dao.insertNote(
                Note(
                    title = title,
                    content = content,
                    categoryId = categoryId,
                    updatedAt = System.currentTimeMillis()
                )
            )
        } else {
            val existing = dao.getNote(id) ?: return@launch
            dao.updateNote(
                existing.copy(
                    title = title,
                    content = content,
                    categoryId = categoryId,
                    updatedAt = System.currentTimeMillis()
                )
            )
        }
    }

    fun toggleFavorite(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isFavorite = !note.isFavorite))
    }

    fun moveToTrash(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isTrashed = true, isFavorite = false, updatedAt = System.currentTimeMillis()))
    }

    fun restore(note: Note) = viewModelScope.launch {
        dao.updateNote(note.copy(isTrashed = false, updatedAt = System.currentTimeMillis()))
    }

    fun deleteForever(note: Note) = viewModelScope.launch { dao.deleteNoteHard(note.id) }

    fun emptyTrash() = viewModelScope.launch { dao.emptyTrash() }

    fun addCategory(name: String) = viewModelScope.launch { dao.insertCategory(Category(name = name)) }

    fun renameCategory(category: Category, name: String) = viewModelScope.launch {
        dao.updateCategory(category.copy(name = name))
    }

    fun deleteCategory(category: Category) = viewModelScope.launch {
        dao.clearCategoryFromNotes(category.id)
        dao.deleteCategory(category.id)
    }
}
