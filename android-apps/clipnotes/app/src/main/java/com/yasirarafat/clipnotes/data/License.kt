package com.yasirarafat.clipnotes.data

import android.content.Context
import android.provider.Settings
import java.security.MessageDigest

/**
 * Simple offline, device-locked activation-key system.
 *
 * The app shows the user a "Device Code" (a stable per-device id). You generate a
 * matching key from that code with the private key-generator page (same SALT), give
 * it to the buyer, and entering it unlocks Pro on THAT device only.
 *
 * Note: the SALT below also lives in the private key-generator page — keep them
 * identical. This is fine for casual paid unlocks; for stronger protection it can
 * later be injected as a build-time secret instead of a constant.
 */
object License {

    private const val SALT = "cn7Q2x9-clipnotes-pro-2026"

    /** Stable per-device code (same across uninstall/reinstall; changes on factory reset). */
    fun deviceCode(context: Context): String {
        val id = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        return (id ?: "UNKNOWN").uppercase()
    }

    /** The key that unlocks a given device code. Mirrored by the key-generator page. */
    fun keyForCode(code: String): String {
        val digest = MessageDigest.getInstance("SHA-256")
            .digest((SALT + code.trim().uppercase()).toByteArray(Charsets.UTF_8))
        val hex = digest.joinToString("") { "%02X".format(it.toInt() and 0xFF) }
        val k = hex.substring(0, 16)
        return listOf(
            k.substring(0, 4), k.substring(4, 8), k.substring(8, 12), k.substring(12, 16)
        ).joinToString("-")
    }

    fun expectedKey(context: Context): String = keyForCode(deviceCode(context))

    fun isValidKey(context: Context, entered: String): Boolean {
        val norm = entered.trim().uppercase().replace("-", "").replace(" ", "")
        val expected = expectedKey(context).replace("-", "")
        return norm.isNotEmpty() && norm == expected
    }
}
