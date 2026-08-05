package com.yasirarafat.clipnotes.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.CloudDownload
import androidx.compose.material.icons.filled.CloudUpload
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.FileDownload
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.AlertDialog
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.yasirarafat.clipnotes.ui.BiometricAuth
import com.yasirarafat.clipnotes.ui.NotesViewModel
import com.yasirarafat.clipnotes.ui.PasswordField
import com.yasirarafat.clipnotes.ui.theme.ClipAccents

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

    // Restore is destructive (replace), so confirm first.
    var confirmRestoreSaved by remember { mutableStateOf(false) }
    var confirmLocalRestore by remember { mutableStateOf(false) }
    var pendingImportUri by remember { mutableStateOf<Uri?>(null) }

    // App-lock dialogs
    var showSetPw by remember { mutableStateOf(false) }
    var showChangePw by remember { mutableStateOf(false) }
    var showRemovePw by remember { mutableStateOf(false) }

    // Pick (create) the backup file once; afterwards you can back up / restore with one tap.
    val setupBackupLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.CreateDocument("application/json")
    ) { uri ->
        if (uri != null) {
            vm.configureBackupFile(uri) { ok ->
                toast(context, if (ok) "Backup file set ✓ — tap 'Back up now' to save" else "Could not set up backup")
            }
        }
    }
    // Restore from a different file (replaces current data — confirmed first).
    val importLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.OpenDocument()
    ) { uri ->
        if (uri != null) {
            pendingImportUri = uri
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
        val options = listOf("Light" to 1, "Dark" to 2)
        options.forEach { (label, value) ->
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable { vm.setTheme(value) }
                    .padding(vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(
                    selected = if (value == 2) vm.themeMode == 2 else vm.themeMode != 2,
                    onClick = { vm.setTheme(value) }
                )
                Spacer(Modifier.size(8.dp))
                Text(label, style = MaterialTheme.typography.bodyLarge)
            }
        }

        Spacer(Modifier.size(12.dp))
        Text("App color", style = MaterialTheme.typography.labelLarge)
        Spacer(Modifier.size(8.dp))
        Row {
            ClipAccents.forEachIndexed { index, accent ->
                val selected = vm.accentIndex == index
                Box(
                    modifier = Modifier
                        .size(40.dp)
                        .padding(end = 12.dp)
                ) {
                    Box(
                        modifier = Modifier
                            .size(32.dp)
                            .clip(CircleShape)
                            .background(accent.light)
                            .border(
                                width = if (selected) 3.dp else 0.dp,
                                color = MaterialTheme.colorScheme.onSurface,
                                shape = CircleShape
                            )
                            .clickable { vm.setAccent(index) }
                    )
                    if (selected) {
                        Icon(
                            Icons.Filled.Check,
                            contentDescription = "Selected",
                            tint = Color.White,
                            modifier = Modifier.size(20.dp).align(Alignment.Center)
                        )
                    }
                }
            }
        }

        Divider16()

        // ---- Backup ----
        SectionTitle("Backup & Restore")

        // On-phone automatic backup (no file, no path).
        Text("On this phone (automatic)", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
        Text(
            "Your notes are saved on this phone automatically and updated on every change. " +
                "Tap Restore to bring them back — no file or path needed. Reinstalling on the " +
                "same Google account (with backup on) also restores them on its own.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Spacer(Modifier.size(8.dp))
        OutlinedButton(
            onClick = { confirmLocalRestore = true },
            modifier = Modifier.fillMaxWidth()
        ) {
            Icon(Icons.Filled.CloudDownload, null, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text("Restore from this phone")
        }

        Spacer(Modifier.size(16.dp))

        // Optional cloud copy (Google Drive / file).
        Text("Cloud backup (optional)", style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.SemiBold)
        if (vm.backupUri == null) {
            Text(
                "Keep an extra copy in Google Drive or your phone storage.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.size(8.dp))
            Button(
                onClick = { setupBackupLauncher.launch("clipnotes-backup.json") },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.CloudUpload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Set up cloud backup")
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
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.CheckCircle, null, tint = Color(0xFF16A34A), modifier = Modifier.size(18.dp))
                Spacer(Modifier.size(8.dp))
                Text(
                    "Cloud backup is set up.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant
                )
            }
            Spacer(Modifier.size(10.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text("Sync to cloud on every change", style = MaterialTheme.typography.bodyLarge)
                    Text(
                        "Keeps the cloud copy always up to date.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                Switch(checked = vm.autoBackup, onCheckedChange = { vm.setAutoBackupEnabled(it) })
            }
            Spacer(Modifier.size(8.dp))
            Button(
                onClick = { vm.backupNow { ok -> toast(context, if (ok) "Backed up ✓" else "Backup failed") } },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.CloudUpload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Back up to cloud now")
            }
            Spacer(Modifier.size(8.dp))
            OutlinedButton(
                onClick = { confirmRestoreSaved = true },
                modifier = Modifier.fillMaxWidth()
            ) {
                Icon(Icons.Filled.CloudDownload, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Restore from cloud")
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

        // ---- App Lock ----
        SectionTitle("App Lock")
        if (!vm.lockEnabled) {
            Text(
                "Set a master password, then lock any note from its ⋮ menu. " +
                    "Locked notes stay hidden until you unlock them.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.size(10.dp))
            Button(onClick = { showSetPw = true }, modifier = Modifier.fillMaxWidth()) {
                Icon(Icons.Filled.Lock, null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("Set master password")
            }
        } else {
            Text(
                "Master password is set. Lock a note from its ⋮ menu; locked notes need this password to open.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant
            )
            Spacer(Modifier.size(10.dp))
            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Button(onClick = { showChangePw = true }) { Text("Change") }
                Spacer(Modifier.width(8.dp))
                OutlinedButton(onClick = { showRemovePw = true }) { Text("Remove") }
                Spacer(Modifier.weight(1f))
                if (vm.revealedIds.isNotEmpty()) {
                    TextButton(onClick = { vm.lockAll(); toast(context, "Locked") }) { Text("Lock now") }
                }
            }
            val fingerprintReady = remember { BiometricAuth.isAvailable(context) }
            Spacer(Modifier.size(10.dp))
            Row(modifier = Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(modifier = Modifier.weight(1f)) {
                    Text("Unlock with fingerprint", style = MaterialTheme.typography.bodyLarge)
                    Text(
                        if (fingerprintReady)
                            "Use your fingerprint instead of typing the password to open locked notes."
                        else
                            "No fingerprint is set up on this phone. Add one in your phone's Settings first.",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant
                    )
                }
                Switch(
                    checked = vm.biometricEnabled && fingerprintReady,
                    enabled = fingerprintReady,
                    onCheckedChange = { vm.enableBiometric(it) }
                )
            }
        }

        Divider16()

        // ---- About ----
        SectionTitle("About")
        Text("Clip Notes  •  version 1.2", style = MaterialTheme.typography.bodyMedium)
        Spacer(Modifier.size(6.dp))
        Text(
            "Save the text you use often and copy it with a single tap. " +
                "Everything is stored privately on your device — no internet required.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant
        )
        Spacer(Modifier.size(12.dp))
        Text(
            "Developed by Md. Yasir Arafat",
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.SemiBold,
            color = MaterialTheme.colorScheme.primary
        )
    }

    if (confirmLocalRestore) {
        AlertDialog(
            onDismissRequest = { confirmLocalRestore = false },
            title = { Text("Restore from this phone?") },
            text = { Text("This replaces your current notes and categories with the automatic on-phone backup.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmLocalRestore = false
                    vm.restoreLocalNow { n ->
                        toast(context, if (n >= 0) "Restored $n note(s)" else "No local backup found yet")
                    }
                }) { Text("Restore") }
            },
            dismissButton = { TextButton(onClick = { confirmLocalRestore = false }) { Text("Cancel") } }
        )
    }
    if (confirmRestoreSaved) {
        AlertDialog(
            onDismissRequest = { confirmRestoreSaved = false },
            title = { Text("Restore from cloud?") },
            text = { Text("This replaces your current notes and categories with the ones saved in your cloud backup file.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmRestoreSaved = false
                    vm.restoreNow { n -> toast(context, if (n >= 0) "Restored $n note(s)" else "Restore failed") }
                }) { Text("Restore") }
            },
            dismissButton = { TextButton(onClick = { confirmRestoreSaved = false }) { Text("Cancel") } }
        )
    }
    pendingImportUri?.let { uri ->
        AlertDialog(
            onDismissRequest = { pendingImportUri = null },
            title = { Text("Restore from this file?") },
            text = { Text("This replaces your current notes and categories with the ones in the selected file.") },
            confirmButton = {
                TextButton(onClick = {
                    pendingImportUri = null
                    vm.importFrom(uri) { n ->
                        toast(context, if (n >= 0) "Restored $n note(s)" else "Restore failed — is it a Clip Notes backup?")
                    }
                }) { Text("Restore") }
            },
            dismissButton = { TextButton(onClick = { pendingImportUri = null }) { Text("Cancel") } }
        )
    }

    if (showSetPw) {
        var p1 by remember { mutableStateOf("") }
        var p2 by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showSetPw = false },
            title = { Text("Set master password") },
            text = {
                Column {
                    PasswordField(p1, { p1 = it }, "New password")
                    Spacer(Modifier.size(8.dp))
                    PasswordField(p2, { p2 = it }, "Confirm password")
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    if (p1.isNotBlank() && p1 == p2) {
                        vm.setMasterPassword(p1); showSetPw = false; toast(context, "Password set")
                    } else toast(context, "Passwords don't match")
                }) { Text("Save") }
            },
            dismissButton = { TextButton(onClick = { showSetPw = false }) { Text("Cancel") } }
        )
    }

    if (showChangePw) {
        var cur by remember { mutableStateOf("") }
        var nw by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showChangePw = false },
            title = { Text("Change master password") },
            text = {
                Column {
                    PasswordField(cur, { cur = it }, "Current password")
                    Spacer(Modifier.size(8.dp))
                    PasswordField(nw, { nw = it }, "New password")
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    if (vm.changeMasterPassword(cur, nw)) {
                        showChangePw = false; toast(context, "Password changed")
                    } else toast(context, "Wrong current password")
                }) { Text("Save") }
            },
            dismissButton = { TextButton(onClick = { showChangePw = false }) { Text("Cancel") } }
        )
    }

    if (showRemovePw) {
        var cur by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { showRemovePw = false },
            title = { Text("Remove master password") },
            text = {
                Column {
                    Text("This unlocks all locked notes. Enter your password to confirm.")
                    Spacer(Modifier.size(8.dp))
                    PasswordField(cur, { cur = it }, "Current password")
                }
            },
            confirmButton = {
                TextButton(onClick = {
                    if (vm.removeMasterPassword(cur)) {
                        showRemovePw = false; toast(context, "Password removed")
                    } else toast(context, "Wrong password")
                }) { Text("Remove") }
            },
            dismissButton = { TextButton(onClick = { showRemovePw = false }) { Text("Cancel") } }
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
