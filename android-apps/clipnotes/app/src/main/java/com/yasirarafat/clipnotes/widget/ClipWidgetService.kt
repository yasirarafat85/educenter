package com.yasirarafat.clipnotes.widget

import android.content.Context
import android.content.Intent
import android.widget.RemoteViews
import android.widget.RemoteViewsService
import com.yasirarafat.clipnotes.R
import com.yasirarafat.clipnotes.data.AppDatabase
import com.yasirarafat.clipnotes.data.Note

class ClipWidgetService : RemoteViewsService() {
    override fun onGetViewFactory(intent: Intent): RemoteViewsFactory =
        ClipRemoteViewsFactory(applicationContext)
}

private class ClipRemoteViewsFactory(
    private val context: Context
) : RemoteViewsService.RemoteViewsFactory {

    private var items: List<Note> = emptyList()

    override fun onCreate() {}

    override fun onDataSetChanged() {
        // Runs on a binder thread, so a blocking Room query is fine here.
        items = AppDatabase.get(context).dao().widgetNotes()
    }

    override fun onDestroy() {
        items = emptyList()
    }

    override fun getCount(): Int = items.size

    override fun getViewAt(position: Int): RemoteViews {
        val note = items[position]
        val label = note.title.ifBlank { note.content }.take(80)
        val views = RemoteViews(context.packageName, R.layout.widget_item)
        views.setTextViewText(R.id.widget_item_text, label)
        val fillIn = Intent().putExtra(
            ClipWidgetProvider.EXTRA_TEXT,
            note.content.ifBlank { note.title }
        )
        views.setOnClickFillInIntent(R.id.widget_item_text, fillIn)
        return views
    }

    override fun getLoadingView(): RemoteViews? = null
    override fun getViewTypeCount(): Int = 1
    override fun getItemId(position: Int): Long = items[position].id
    override fun hasStableIds(): Boolean = true
}
