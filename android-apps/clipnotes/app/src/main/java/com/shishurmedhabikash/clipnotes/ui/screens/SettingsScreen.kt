package com.shishurmedhabikash.clipnotes.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.shishurmedhabikash.clipnotes.ui.NotesViewModel

@Composable
fun SettingsScreen(vm: NotesViewModel) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp)
    ) {
        Text(
            "Appearance",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.primary
        )
        Spacer(Modifier.size(8.dp))

        val options = listOf(
            "Follow system" to 0,
            "Light" to 1,
            "Dark" to 2
        )
        options.forEach { (label, value) ->
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable { vm.setTheme(value) }
                    .padding(vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(
                    selected = vm.themeMode == value,
                    onClick = { vm.setTheme(value) }
                )
                Spacer(Modifier.size(8.dp))
                Text(label, style = MaterialTheme.typography.bodyLarge)
            }
        }

        Spacer(Modifier.size(16.dp))
        HorizontalDivider()
        Spacer(Modifier.size(16.dp))

        Text(
            "About",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            color = MaterialTheme.colorScheme.primary
        )
        Spacer(Modifier.size(8.dp))
        Text("Clip Notes  •  version 1.0", style = MaterialTheme.typography.bodyMedium)
        Spacer(Modifier.size(6.dp))
        Text(
            "Save the text you use often and copy it with a single tap. " +
                "Everything is stored privately on your device — no internet required.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
    }
}
