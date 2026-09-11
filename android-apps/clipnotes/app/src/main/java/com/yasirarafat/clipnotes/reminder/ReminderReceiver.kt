package com.yasirarafat.clipnotes.reminder

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.RingtoneManager
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.yasirarafat.clipnotes.MainActivity
import com.yasirarafat.clipnotes.R
import com.yasirarafat.clipnotes.data.AppDatabase

class ReminderReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            // Reschedule future reminders after reboot (off the main thread).
            val pending = goAsync()
            Thread {
                try { rescheduleAll(context) } finally { pending.finish() }
            }.start()
            return
        }
        val id = intent.getLongExtra(EXTRA_ID, 0L)
        val title = intent.getStringExtra(EXTRA_TITLE).orEmpty()
        val text = intent.getStringExtra(EXTRA_TEXT).orEmpty()
        showNotification(context, id, title, text)
    }

    /**
     * Alarm-style reminder sound.
     *
     * NOTE: a NotificationChannel's sound/importance are IMMUTABLE once created,
     * so changing them requires a NEW channel id — hence [CHANNEL] is versioned.
     * The old channel is deleted so users don't see a stale duplicate in Settings.
     */
    private fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            runCatching { nm.deleteNotificationChannel(OLD_CHANNEL) }

            val channel = NotificationChannel(CHANNEL, "Reminders", NotificationManager.IMPORTANCE_HIGH)
            channel.description = "Note reminders"
            channel.enableVibration(true)
            channel.vibrationPattern = longArrayOf(0, 500, 300, 500, 300, 500)
            channel.enableLights(true)
            // Play at ALARM volume so it is actually audible, like a real alarm.
            val attrs = AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build()
            channel.setSound(alarmSound(), attrs)
            nm.createNotificationChannel(channel)
        }
    }

    /** Default alarm tone, falling back to the notification tone. */
    private fun alarmSound(): Uri =
        RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)

    private fun showNotification(context: Context, id: Long, title: String, text: String) {
        ensureChannel(context)
        val openIntent = PendingIntent.getActivity(
            context, id.toInt(), Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val builder = NotificationCompat.Builder(context, CHANNEL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title.ifBlank { "Clip Notes reminder" })
            .setContentText(text.ifBlank { "You have a reminder" })
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setContentIntent(openIntent)
        // Pre-Android 8 has no channels, so sound/vibration go on the builder.
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            builder.setSound(alarmSound(), AudioManager.STREAM_ALARM)
                .setVibrate(longArrayOf(0, 500, 300, 500, 300, 500))
        }

        val allowed = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
            ContextCompat.checkSelfPermission(
                context, Manifest.permission.POST_NOTIFICATIONS
            ) == PackageManager.PERMISSION_GRANTED
        if (allowed) {
            NotificationManagerCompat.from(context).notify(id.toInt(), builder.build())
        }
    }

    private fun rescheduleAll(context: Context) {
        try {
            val now = System.currentTimeMillis()
            AppDatabase.get(context).dao().remindersSync().forEach { n ->
                val t = n.reminderAt ?: return@forEach
                if (t > now) ReminderScheduler.schedule(context, n.id, n.title, n.content, t)
            }
        } catch (_: Exception) {
        }
    }

    companion object {
        const val ACTION_FIRE = "com.yasirarafat.clipnotes.REMINDER_FIRE"
        const val EXTRA_ID = "id"
        const val EXTRA_TITLE = "title"
        const val EXTRA_TEXT = "text"
        /** Versioned: bump this whenever the channel's sound/importance changes. */
        const val CHANNEL = "reminders_v2"
        private const val OLD_CHANNEL = "reminders"
    }
}
