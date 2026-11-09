// Placeholder: the portal's review-and-sign page is built against these props (docs/service-packages.md, slice 3a).
type Props = {
    service: { id: string; name: string; status: string };
    accept_url: string | null;
};

export default function PortalService({ service }: Props) {
    return <p className="wrap-anywhere">{service.name}</p>;
}
