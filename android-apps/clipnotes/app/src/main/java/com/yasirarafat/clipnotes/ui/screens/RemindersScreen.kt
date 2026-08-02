package com.yasirarafat.clipnotes.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.data.Note
import java.text.SimpleDateFormat
import java.util.Locale

@Composable
fun RemindersScreen(reminders: List<Note>, onOpen: (Note) -> Unit) {
    if (reminders.isEmpty()) {
        EmptyState("No reminders yet. Open a note and tap Set under Reminder.")
        return
    }
    val fmt = remember { SimpleDateFormat("EEE, MMM d • h:mm a", Locale.getDefault()) }
    val now = System.currentTimeMillis()
    LazyColumn(
        modifier = Modifier.fillMaxSize(),
        contentPadding = PaddingValues(12.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp)
    ) {
        items(reminders, key = { it.id }) { note ->
            val time = note.reminderAt ?: 0L
            Card(
                modifier = Modifier.fillMaxWidth().clickable { onOpen(note) },
                colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
                elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
            ) {
                Row(
                    modifier = Modifier.fillMaxWidth().padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(
                        Icons.Filled.Notifications,
                        contentDescription = null,
                        tint = if (time in 1 until now) MaterialTheme.colorScheme.outline
                        else MaterialTheme.colorScheme.primary
                    )
                    Spacer(Modifier.size(12.dp))
                    Column(modifier = Modifier.fillMaxWidth()) {
                        Text(
                            if (time > 0) fmt.format(time) else "—",
                            style = MaterialTheme.typography.labelLarge,
                            color = if (time in 1 until now) MaterialTheme.colorScheme.outline
                            else MaterialTheme.colorScheme.primary
                        )
                        Spacer(Modifier.size(2.dp))
                        Text(
                            note.title.ifBlank { note.content }.ifBlank { "(empty)" },
                            style = MaterialTheme.typography.titleSmall,
                            maxLines = 2,
                            overflow = TextOverflow.Ellipsis,
                            color = MaterialTheme.colorScheme.onSurface
                        )
                    }
                }
            }
        }
    }
}
