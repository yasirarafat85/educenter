package com.shishurmedhabikash.clipnotes

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import com.shishurmedhabikash.clipnotes.ui.ClipApp
import com.shishurmedhabikash.clipnotes.ui.NotesViewModel
import com.shishurmedhabikash.clipnotes.ui.theme.ClipNotesTheme

class MainActivity : ComponentActivity() {

    private val vm: NotesViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent {
            val systemDark = isSystemInDarkTheme()
            val dark = when (vm.themeMode) {
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
}
