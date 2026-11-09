// Placeholder: the sign-up request queue is built against these props.
type Props = { requests: { id: string; status: string }[] };

export default function PackageRequests({ requests }: Props) {
    return (
        <ul>
            {requests.map((request) => (
                <li key={request.id}>{request.status}</li>
            ))}
        </ul>
    );
}
