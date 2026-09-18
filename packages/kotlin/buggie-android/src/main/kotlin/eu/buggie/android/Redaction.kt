package eu.buggie.android

import android.text.InputType
import android.view.View
import android.view.ViewGroup
import android.widget.TextView

/**
 * The tag a layout file can carry: `android:tag="buggie-redact"`.
 *
 * Most Android interfaces are declared in XML, where there is nowhere to set a
 * property. Without this, marking a field would mean finding it in code first, and a
 * redaction you have to remember to wire up is one you will forget.
 */
public const val BUGGIE_REDACT_TAG: String = "buggie-redact"

/**
 * Mark a view so it never appears in a screenshot — the native equivalent of the
 * widget's `data-buggie-redact`, and of iOS's `buggieRedacted`.
 */
public var View.buggieRedacted: Boolean
    get() = getTag(R.id.buggie_redacted) == true
    set(value) {
        setTag(R.id.buggie_redacted, value)
    }

/**
 * Whether this view is a password field, by its input type.
 *
 * The variation bits are only meaningful inside their class: 0x10 is
 * `TYPE_NUMBER_VARIATION_PASSWORD` and also `TYPE_TEXT_VARIATION_URI`. Test the
 * variation without the class and a PIN entry goes unredacted while a URL box is
 * hidden for no reason — so the class is checked first, always.
 */
internal val View.isPasswordField: Boolean
    get() {
        val inputType = (this as? TextView)?.inputType ?: return false
        val variation = inputType and InputType.TYPE_MASK_VARIATION

        return when (inputType and InputType.TYPE_MASK_CLASS) {
            InputType.TYPE_CLASS_TEXT -> variation in TEXT_PASSWORD_VARIATIONS
            InputType.TYPE_CLASS_NUMBER -> variation == InputType.TYPE_NUMBER_VARIATION_PASSWORD
            else -> false
        }
    }

private val TEXT_PASSWORD_VARIATIONS = setOf(
    InputType.TYPE_TEXT_VARIATION_PASSWORD,
    InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD,
    InputType.TYPE_TEXT_VARIATION_WEB_PASSWORD,
)

/** Everything under this view that must be out of the picture. */
internal fun View.buggieRedactedViews(): List<View> = buildList { collectRedacted(this) }

private fun View.collectRedacted(into: MutableList<View>) {
    if (buggieRedacted || tag == BUGGIE_REDACT_TAG || isPasswordField) {
        into += this

        // No need to look inside: hiding a view hides everything it contains, and
        // hiding a child of an already-hidden view only makes the restore harder.
        return
    }

    if (this is ViewGroup) {
        for (index in 0 until childCount) getChildAt(index).collectRedacted(into)
    }
}
