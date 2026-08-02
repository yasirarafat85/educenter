package com.yasirarafat.clipnotes.data

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query
import androidx.room.Update
import kotlinx.coroutines.flow.Flow

@Dao
interface AppDao {

    // ---- Notes ----
    @Query("SELECT * FROM notes WHERE isTrashed = 0 ORDER BY isPinned DESC, updatedAt DESC")
    fun activeNotes(): Flow<List<Note>>

    @Query("SELECT * FROM notes WHERE isTrashed = 0 AND isFavorite = 1 ORDER BY isPinned DESC, updatedAt DESC")
    fun favoriteNotes(): Flow<List<Note>>

    @Query("UPDATE notes SET copyCount = copyCount + 1 WHERE id = :id")
    suspend fun incrementCopy(id: Long)

    @Query("SELECT * FROM notes WHERE isTrashed = 1 ORDER BY updatedAt DESC")
    fun trashedNotes(): Flow<List<Note>>

    @Query("SELECT * FROM notes WHERE id = :id LIMIT 1")
    suspend fun getNote(id: Long): Note?

    @Insert
    suspend fun insertNote(note: Note): Long

    @Update
    suspend fun updateNote(note: Note)

    @Query("DELETE FROM notes WHERE id = :id")
    suspend fun deleteNoteHard(id: Long)

    @Query("DELETE FROM notes WHERE isTrashed = 1")
    suspend fun emptyTrash()

    // ---- Categories ----
    @Query("SELECT * FROM categories ORDER BY name COLLATE NOCASE ASC")
    fun categories(): Flow<List<Category>>

    @Insert
    suspend fun insertCategory(category: Category): Long

    @Update
    suspend fun updateCategory(category: Category)

    @Query("DELETE FROM categories WHERE id = :id")
    suspend fun deleteCategory(id: Long)

    @Query("UPDATE notes SET categoryId = NULL WHERE categoryId = :id")
    suspend fun clearCategoryFromNotes(id: Long)

    // ---- Export / Import ----
    @Query("SELECT * FROM categories")
    suspend fun allCategoriesOnce(): List<Category>

    /** ALL notes, including trashed ones, so backups are complete. */
    @Query("SELECT * FROM notes")
    suspend fun allNotesOnce(): List<Note>

    @Query("SELECT id FROM categories WHERE name = :name LIMIT 1")
    suspend fun categoryIdByName(name: String): Long?

    @Query("SELECT COUNT(*) FROM notes WHERE title = :title AND content = :content AND isTrashed = :trashed")
    suspend fun countMatchingNotes(title: String, content: String, trashed: Boolean): Int
}
