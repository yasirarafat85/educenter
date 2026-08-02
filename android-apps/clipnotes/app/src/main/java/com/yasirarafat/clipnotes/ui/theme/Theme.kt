package com.yasirarafat.clipnotes.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** One selectable accent: light-mode primary, dark-mode primary, and container tints. */
data class ClipAccent(
    val light: Color,
    val dark: Color,
    val container: Color,
    val onContainer: Color
)

/** The accent palette the user can pick from in Settings. Order matters (stored as index). */
val ClipAccents = listOf(
    ClipAccent(Color(0xFF2563EB), Color(0xFF60A5FA), Color(0xFFDCE7FF), Color(0xFF0B2A6B)), // Blue
    ClipAccent(Color(0xFF059669), Color(0xFF34D399), Color(0xFFCFF5E7), Color(0xFF04372A)), // Emerald
    ClipAccent(Color(0xFF7C3AED), Color(0xFFA78BFA), Color(0xFFEADDFF), Color(0xFF2A0A5E)), // Purple
    ClipAccent(Color(0xFF0D9488), Color(0xFF2DD4BF), Color(0xFFC9F2EC), Color(0xFF00302B)), // Teal
    ClipAccent(Color(0xFFEA580C), Color(0xFFFB923C), Color(0xFFFFE0CC), Color(0xFF4A1A00))  // Orange
)

/** Per-note strip colours. Index 0 = none (no strip). Stored on each note. */
val NoteStripColors = listOf(
    Color(0x00000000), // 0 none
    Color(0xFFEF4444), // red
    Color(0xFFF59E0B), // amber
    Color(0xFF10B981), // green
    Color(0xFF3B82F6), // blue
    Color(0xFF8B5CF6), // purple
    Color(0xFFEC4899)  // pink
)

private val LightBase = lightColorScheme(
    onPrimary = Color.White,
    background = Color(0xFFF5F6FA),
    onBackground = Color(0xFF1A1C1E),
    surface = Color.White,
    onSurface = Color(0xFF1A1C1E),
    surfaceVariant = Color(0xFFEDEFF4),
    onSurfaceVariant = Color(0xFF44474E),
    outline = Color(0xFFC7C9D0)
)

private val DarkBase = darkColorScheme(
    background = Color(0xFF111318),
    onBackground = Color(0xFFE3E2E6),
    surface = Color(0xFF1B1E24),
    onSurface = Color(0xFFE3E2E6),
    surfaceVariant = Color(0xFF2A2E36),
    onSurfaceVariant = Color(0xFFC3C6CF),
    outline = Color(0xFF3A3E46)
)

@Composable
fun ClipNotesTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    accentIndex: Int = 0,
    content: @Composable () -> Unit
) {
    val accent = ClipAccents.getOrElse(accentIndex) { ClipAccents[0] }
    val colorScheme = if (darkTheme) {
        DarkBase.copy(
            primary = accent.dark,
            onPrimary = Color(0xFF06121F),
            primaryContainer = accent.light,
            onPrimaryContainer = accent.container,
            secondary = accent.dark
        )
    } else {
        LightBase.copy(
            primary = accent.light,
            onPrimary = Color.White,
            primaryContainer = accent.container,
            onPrimaryContainer = accent.onContainer,
            secondary = accent.light
        )
    }
    MaterialTheme(
        colorScheme = colorScheme,
        typography = Typography(),
        content = content
    )
}
