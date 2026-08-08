package com.yasirarafat.clipnotes.reminder

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build

/**
 * Schedules/cancels a note's reminder alarm.
 *
 * Uses [AlarmManager.setAlarmClock] as the primary path: it always fires exactly
 * (even in Doze) and — unlike setExactAndAllowWhileIdle — needs NO
 * SCHEDULE_EXACT_ALARM permission, so reminders are punctual on every Android
 * version while staying fully Play-policy safe. Falls back to exact/inexact
 * alarms only if the alarm-clock path is unavailable, so it never crashes.
 */
object ReminderScheduler {

    private fun pending(context: Context, noteId: Long, title: String, text: String): PendingIntent {
        val intent = Intent(context, ReminderReceiver::class.java).apply {
            action = ReminderReceiver.ACTION_FIRE
            putExtra(ReminderReceiver.EXTRA_ID, noteId)
            putExtra(ReminderReceiver.EXTRA_TITLE, title)
            putExtra(ReminderReceiver.EXTRA_TEXT, text)
        }
        return PendingIntent.getBroadcast(
            context,
            noteId.toInt(),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    /** PendingIntent that opens the app when the user taps the alarm-clock icon. */
    private fun showApp(context: Context, noteId: Long): PendingIntent {
        val launch = context.packageManager.getLaunchIntentForPackage(context.packageName)
            ?: Intent()
        return PendingIntent.getActivity(
            context,
            noteId.toInt(),
            launch,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }

    fun schedule(context: Context, noteId: Long, title: String, text: String, timeMillis: Long) {
        if (timeMillis <= System.currentTimeMillis()) return
        val am = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        val pi = pending(context, noteId, title, text)
        try {
            // Exact, wakes from Doze, needs no special permission.
            am.setAlarmClock(AlarmManager.AlarmClockInfo(timeMillis, showApp(context, noteId)), pi)
        } catch (e: Exception) {
            // Extremely unlikely, but never let a reminder crash the save.
            try {
                val canExact = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S)
                    am.canScheduleExactAlarms() else true
                if (canExact) am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, timeMillis, pi)
                else am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, timeMillis, pi)
            } catch (e2: SecurityException) {
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, timeMillis, pi)
            }
        }
    }

    fun cancel(context: Context, noteId: Long) {
        val am = context.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        am.cancel(pending(context, noteId, "", ""))
    }
}
