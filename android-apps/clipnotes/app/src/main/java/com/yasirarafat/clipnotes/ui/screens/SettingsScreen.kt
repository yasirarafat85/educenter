package com.yasirarafat.clipnotes.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.CloudDownload
import androidx.compose.material.icons.filled.CloudUpload
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.FileDownload
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Button
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.ui.NotesViewModel

private fun toast(context: Context, msg: String) =
    Toast.makeText(context, msg, Toast.LENGTH_SHORT).show()

private fun copyText(context: Context, text: String) {
    val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    cm.setPrimaryClip(ClipData.newPlainText("Device code", text))
    toast(context, "Copied")
}

@Composable
fun SettingsScreen(vm: NotesViewModel) {
    val context = LocalContext.current

    // Pick (create) the backup file once; after that backups are automatic / one-tap.
    val setupBackupLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.CreateDocument("application/json")
    ) { uri ->
        if (uri != null) {
            vm.configureBackupFile(uri) { ok ->
                toast(context, if (ok) "Auto backup set up ✓" else "Could not set up backup")
            }
        }
    }
    // Import/restore from a different file (does not change the auto-backup file).
    val importLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri ->
        if (uri != null) {
            vm.importFrom(uri) { n ->
                toast(context, if (n >= 0) "Imported $n note(s)" else "Import failed — is it a Clip Notes backup?")
            }
        }
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp)
    ) {
        // ---- Appearance ----
        SectionTitle("Appearance")
        val options = listOf("Follow system" to 0, "Light" to 1, "Dark" to 2)
        options.forEach { (label, value) ->
            val locked = value == 2 && !vm.isPro
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable {
                        if (locked) toast(context, "Unlock Pro below to use the Dark theme")
                        else vm.setTheme(value)
                    }
                    .padding(vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(
                    selected = vm.themeMode == value,
                    onClick = {
                        if (locked) toast(context, "Unlock Pro below to use the Dark theme")
                        else vm.setTheme(value)
                    },
                    enabled = !locked
                )
                Spacer(Modifier.size(8.dp))
                Text(label, style = MaterialTheme.typography.bodyLarge)
                if (locked) {
                    Spacer(Modifier.size(8.dp))
                    Icon(Icons.Filled.Lock, null, modifier = Modifier.size(16.dp), tint = MaterialTheme.colorScheme.primary)
                    Spacer(Modifier.size(2.dp))
                    Text("Pro", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.primary)
                }
            }
        }

        Divider16()

        // ---- Backup (Pro) ----
        SectionTitle("Backup & Restore")
        if (!vm.isPro) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    Icons.Filled.Lock, null,
                    modifier = Modifier.size(18.dp),
                    tint = MaterialTheme.colorScheme.primary
                )
                Spacer(Modifier.size(8.dp))
                Text(
                    "Unlock Pro (below) to back up and restore your notes.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
        } else if (vm.backupUri == null) {
            // First-time setup: choose the backup file once (Google Drive or phone).
            Text(
                "Choose a backup file once — in Google Drive (cloud) or your phone. " +
                    "After that, your notes, categories, trash and settings back up " +
                    "automatically to that file, and you can restore with one tap.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.size(12.dp))
            Button(
                onClick = { setupBackupLauncher.launch("clipnotes-backup.json") },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.CloudUpload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Set up auto backup")
            }
            Spacer(Modifier.size(8.dp))
            OutlinedButton(
                onClick = { importLauncher.launch(arrayOf("*/*")) },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.FileDownload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Restore from a file")
            }
        } else {
            // Configured: automatic + one-tap, no picker needed.
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.CheckCircle, null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
                Spacer(Modifier.size(8.dp))
                Text(
                    "Backup file is set. Notes, categories, trash & settings are saved here.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            Spacer(Modifier.size(10.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text("Auto backup on changes", modifier = Modifier.weight(1f), style = MaterialTheme.typography.bodyLarge)
                Switch(checked = vm.autoBackup, onCheckedChange = { vm.setAutoBackupEnabled(it) })
            }
            Spacer(Modifier.size(8.dp))
            Button(
                onClick = { vm.backupNow { ok -> toast(context, if (ok) "Backed up ✓" else "Backup failed") } },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.CloudUpload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Back up now")
            }
            Spacer(Modifier.size(8.dp))
            OutlinedButton(
                onClick = { vm.restoreNow { n -> toast(context, if (n >= 0) "Restored $n note(s)" else "Restore failed") } },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.CloudDownload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Restore now")
            }
            Spacer(Modifier.size(6.dp))
            Row(modifier = Modifier.fillMaxWidth()) {
                TextButton(onClick = { setupBackupLauncher.launch("clipnotes-backup.json") }) {
                    Text("Change file")
                }
                Spacer(Modifier.weight(1f))
                TextButton(onClick = { importLauncher.launch(arrayOf("*/*")) }) {
                    Text("Restore from another file")
                }
            }
        }

        Divider16()

        // ---- Pro ----
        SectionTitle("Clip Notes Pro")
        if (vm.isPro) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.CheckCircle, null, tint = Color(0xFF16A34A))
                Spacer(Modifier.size(8.dp))
                Text("Pro is active on this device. Thank you!", style = MaterialTheme.typography.bodyLarge)
            }
        } else {
            var keyInput by remember { mutableStateOf("") }
            var message by remember { mutableStateOf("") }
            val deviceCode = remember { vm.deviceCode() }

            Text(
                "Pro unlocks the Dark theme and Backup & Restore. To activate, share your " +
                    "Device Code, then enter the key you receive.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.size(10.dp))
            Text("Your Device Code", style = MaterialTheme.typography.labelLarge)
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    deviceCode,
                    style = MaterialTheme.typography.bodyLarge,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f)
                )
                IconButton(onClick = { copyText(context, deviceCode) }) {
                    Icon(Icons.Filled.ContentCopy, contentDescription = "Copy device code")
                }
            }
            Spacer(Modifier.size(8.dp))
            OutlinedTextField(
                value = keyInput,
                onValueChange = { keyInput = it },
                label = { Text("Activation key") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth()
            )
            Spacer(Modifier.size(8.dp))
            Button(
                onClick = {
                    if (vm.tryUnlock(keyInput)) {
                        message = ""
                        toast(context, "Activated! Pro unlocked.")
                    } else {
                        message = "That key is not valid for this device. Check and try again."
                    }
                },
                enabled = keyInput.isNotBlank(),
                modifier = Modifier.fillMaxWidth()
            ) {
                Text("Activate Pro")
            }
            if (message.isNotEmpty()) {
                Spacer(Modifier.size(6.dp))
                Text(message, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
            }
        }

        Divider16()

        // ---- About ----
        SectionTitle("About")
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

@Composable
private fun SectionTitle(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.titleMedium,
        fontWeight = FontWeight.Bold,
        color = MaterialTheme.colorScheme.primary
    )
    Spacer(Modifier.size(8.dp))
}

@Composable
private fun Divider16() {
    Spacer(Modifier.size(16.dp))
    HorizontalDivider()
    Spacer(Modifier.size(16.dp))
}
