package com.yasirarafat.clipnotes.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val Blue = Color(0xFF2563EB)
private val BlueDark = Color(0xFF1E40AF)
private val BlueLight = Color(0xFF60A5FA)

private val LightColors = lightColorScheme(
    primary = Blue,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFDCE7FF),
    onPrimaryContainer = Color(0xFF0B2A6B),
    secondary = Color(0xFF3B82F6),
    onSecondary = Color.White,
    background = Color(0xFFF5F6FA),
    onBackground = Color(0xFF1A1C1E),
    surface = Color.White,
    onSurface = Color(0xFF1A1C1E),
    surfaceVariant = Color(0xFFEDEFF4),
    onSurfaceVariant = Color(0xFF44474E),
    outline = Color(0xFFC7C9D0)
)

private val DarkColors = darkColorScheme(
    primary = BlueLight,
    onPrimary = Color(0xFF0B2A6B),
    primaryContainer = BlueDark,
    onPrimaryContainer = Color(0xFFDCE7FF),
    secondary = Color(0xFF93C5FD),
    onSecondary = Color(0xFF0B2A6B),
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
    content: @Composable () -> Unit
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = Typography(),
        content = content
    )
}
