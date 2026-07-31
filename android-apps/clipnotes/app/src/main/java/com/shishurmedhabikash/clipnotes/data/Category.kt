package com.shishurmedhabikash.clipnotes.data

import androidx.room.Entity
import androidx.room.PrimaryKey

/** A folder/label used to organise notes. */
@Entity(tableName = "categories")
data class Category(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val name: String
)
