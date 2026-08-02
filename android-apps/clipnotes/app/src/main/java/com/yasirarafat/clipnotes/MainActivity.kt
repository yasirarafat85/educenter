package com.yasirarafat.clipnotes

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import com.yasirarafat.clipnotes.ui.ClipApp
import com.yasirarafat.clipnotes.ui.NotesViewModel
import com.yasirarafat.clipnotes.ui.theme.ClipNotesTheme

class MainActivity : ComponentActivity() {

    private val vm: NotesViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        handleShare(intent)
        setContent {
            val systemDark = isSystemInDarkTheme()
            // Dark theme is a Pro feature: free users always get the light theme.
            val dark = vm.isPro && when (vm.themeMode) {
                1 -> false
                2 -> true
                else -> systemDark
            }
            ClipNotesTheme(darkTheme = dark) {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = androidx.compose.material3.MaterialTheme.colorScheme.background
                ) {
                    ClipApp(vm)
                }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        handleShare(intent)
    }

    /** If launched via the Android share sheet with text, stash it for a new note. */
    private fun handleShare(intent: Intent?) {
        if (intent?.action == Intent.ACTION_SEND && intent.type == "text/plain") {
            val text = intent.getStringExtra(Intent.EXTRA_TEXT)
            if (!text.isNullOrBlank()) vm.stashSharedText(text)
        }
    }
}
