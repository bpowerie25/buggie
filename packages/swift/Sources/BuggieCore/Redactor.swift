import Foundation

/// Keeps out of a report the things that should never leave a device.
///
/// The same rules as the web widget: no credentials in URLs, nothing unbounded, and
/// only what the host application chose to hand over.
public enum Redactor {
    /// Query parameters that look like credentials.
    private static let secretParameters: Set<String> = [
        "token", "key", "secret", "password", "passwd", "auth", "session",
        "sig", "signature", "access_token", "api_key", "code",
    ]

    /// Replace credential-shaped query values, and drop the fragment entirely —
    /// OAuth flows routinely park tokens there and it is never useful to us.
    public static func safeURL(_ raw: String) -> String {
        guard var components = URLComponents(string: raw) else {
            return "[unparseable url]"
        }

        components.fragment = nil

        if let items = components.queryItems {
            components.queryItems = items.map { item in
                secretParameters.contains(item.name.lowercased())
                    ? URLQueryItem(name: item.name, value: "[redacted]")
                    : item
            }
        }

        return components.string ?? "[unparseable url]"
    }

    /// Bound a string so one enormous log line cannot fill the payload.
    public static func truncate(_ value: String, to limit: Int) -> String {
        guard value.count > limit else { return value }

        return String(value.prefix(limit)) + "…"
    }

    /// Only the identity fields the host app chose to give us, never a scrape.
    public static func identity(_ raw: [String: String]) -> [String: String] {
        raw.filter { !$0.value.isEmpty }
    }
}
