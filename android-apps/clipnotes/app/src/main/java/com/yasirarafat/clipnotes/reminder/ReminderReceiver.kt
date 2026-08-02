package com.yasirarafat.clipnotes.reminder

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
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

    private fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(CHANNEL, "Reminders", NotificationManager.IMPORTANCE_HIGH)
            channel.description = "Note reminders"
            val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            nm.createNotificationChannel(channel)
        }
    }

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
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(openIntent)

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
        const val CHANNEL = "reminders"
    }
}
