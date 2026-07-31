package com.yasirarafat.clipnotes.data

import androidx.room.Entity
import androidx.room.PrimaryKey

/** A saved snippet of text the user can copy with one tap. */
@Entity(tableName = "notes")
data class Note(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val title: String = "",
    val content: String = "",
    val categoryId: Long? = null,
    val isFavorite: Boolean = false,
    val isTrashed: Boolean = false,
    val updatedAt: Long = System.currentTimeMillis()
)
